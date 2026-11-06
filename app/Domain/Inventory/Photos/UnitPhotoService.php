<?php

namespace App\Domain\Inventory\Photos;

use App\Domain\Inventory\Models\Unit;
use App\Domain\Inventory\Models\UnitPhoto;
use App\Domain\Tenancy\Audit\AuditLogger;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\ImageManager;
use LogicException;
use Throwable;

/**
 * Foto delle unità (11, attività 2.2; ADR-0006, integrazione intervention/image).
 *
 * Ogni immagine caricata si decodifica e si ricodifica in WebP con GD in due misure (larga e
 * miniatura): la ricodifica elimina tutti i metadati EXIF, compresa la posizione GPS (09). L'originale
 * non si conserva. I file stanno sul disco privato con percorsi fatti solo di identificativi e si
 * leggono solo tramite URL firmati temporanei. La copertina è la foto in posizione 0.
 */
final class UnitPhotoService
{
    public function __construct(
        private readonly FilesystemFactory $filesystems,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  string  $sourcePath  file temporaneo già validato (tipo e peso)
     */
    public function upload(Unit $unit, string $sourcePath): UnitPhoto
    {
        $count = UnitPhoto::query()->where('unit_id', $unit->id)->count();
        $max = self::config('max_per_unit');

        if ($count >= $max) {
            throw ValidationException::withMessages(['photo' => __('inventory.photos.limit', ['max' => $max])]);
        }

        $id = (string) Str::uuid7();
        $base = 'units/'.$unit->tenant_id.'/'.$unit->id.'/'.$id;
        $large = $this->encode($sourcePath, self::config('sizes.large'));
        $thumbnail = $this->encode($sourcePath, self::config('sizes.thumbnail'));

        $disk = $this->disk();
        $paths = ['large' => $base.'-'.self::config('sizes.large').'.webp', 'thumbnail' => $base.'-'.self::config('sizes.thumbnail').'.webp'];

        try {
            $disk->put($paths['large'], $large['bytes']);
            $disk->put($paths['thumbnail'], $thumbnail['bytes']);

            $photo = new UnitPhoto;
            $photo->id = $id;
            $photo->forceFill([
                'unit_id' => $unit->id,
                'disk' => self::diskName(),
                'path' => $paths['large'],
                'thumbnail_path' => $paths['thumbnail'],
                'mime_type' => 'image/webp',
                'size_bytes' => strlen($large['bytes']),
                'width' => $large['width'],
                'height' => $large['height'],
                'position' => $count,
            ])->save();
        } catch (Throwable $e) {
            $disk->delete(array_values($paths));

            throw $e;
        }

        $this->audit->record('unit_photo.created', 'unit_photo', $photo->id, ['unit_id' => $unit->id, 'position' => $count]);

        return $photo;
    }

    public function delete(UnitPhoto $photo): void
    {
        $unitId = $photo->unit_id;
        $files = array_filter([$photo->path, $photo->thumbnail_path]);
        $photo->delete();

        $this->compact($unitId);
        $this->audit->record('unit_photo.deleted', 'unit_photo', $photo->id, ['unit_id' => $unitId]);

        // I file si eliminano solo dopo il commit: se la transazione fallisce la foto resta intatta.
        $disk = $this->disk();
        DB::afterCommit(fn () => $disk->delete($files));
    }

    /**
     * Nuovo ordine: l'elenco deve contenere tutte e sole le foto dell'unità; la prima è la copertina.
     *
     * @param  list<string>  $photoIds
     */
    public function reorder(Unit $unit, array $photoIds): void
    {
        $photos = UnitPhoto::query()->where('unit_id', $unit->id)->lockForUpdate()->get()->keyBy('id');
        $ids = array_map('strtolower', $photoIds);

        if (count($ids) !== $photos->count() || array_diff($ids, $photos->keys()->all()) !== []) {
            throw ValidationException::withMessages(['photo_ids' => __('inventory.photos.order_mismatch')]);
        }

        foreach ($ids as $position => $id) {
            $photo = $photos->get($id);

            if ($photo instanceof UnitPhoto && $photo->position !== $position) {
                $photo->forceFill(['position' => $position])->save();
            }
        }

        $this->audit->record('unit_photo.reordered', 'unit', $unit->id, ['count' => count($ids)]);
    }

    /**
     * URL firmati temporanei delle due misure (mai URL permanenti).
     *
     * @return array{large: string, thumbnail: string}
     */
    public function urls(UnitPhoto $photo): array
    {
        $disk = $this->adapter($photo->disk);
        $expires = now()->addMinutes(self::config('url_ttl_minutes'));

        return [
            'large' => $disk->temporaryUrl($photo->path, $expires),
            'thumbnail' => $disk->temporaryUrl($photo->thumbnail_path ?? $photo->path, $expires),
        ];
    }

    /**
     * Decodifica (con rotazione secondo l'orientamento EXIF), riduce alla larghezza indicata senza
     * ingrandire e ricodifica in WebP: il risultato non contiene metadati.
     *
     * @return array{bytes: string, width: int, height: int}
     */
    private function encode(string $sourcePath, int $width): array
    {
        $image = ImageManager::gd(autoOrientation: true, strip: true)->read($sourcePath);
        $image->scaleDown(width: $width);
        $encoded = $image->toWebp(quality: self::config('webp_quality'));

        return ['bytes' => $encoded->toString(), 'width' => $image->width(), 'height' => $image->height()];
    }

    private function compact(string $unitId): void
    {
        $photos = UnitPhoto::query()->where('unit_id', $unitId)->orderBy('position')->orderBy('id')->lockForUpdate()->get();

        foreach ($photos->values() as $position => $photo) {
            if ($photo->position !== $position) {
                $photo->forceFill(['position' => $position])->save();
            }
        }
    }

    private function disk(): FilesystemAdapter
    {
        return $this->adapter(self::diskName());
    }

    private function adapter(string $name): FilesystemAdapter
    {
        $disk = $this->filesystems->disk($name);

        if (! $disk instanceof FilesystemAdapter) {
            throw new LogicException('Disco delle foto non supportato.');
        }

        return $disk;
    }

    private static function diskName(): string
    {
        $disk = config('photos.disk');

        return is_string($disk) ? $disk : 'local';
    }

    private static function config(string $key): int
    {
        $value = config('photos.'.$key);

        return is_numeric($value) ? (int) $value : 0;
    }
}
