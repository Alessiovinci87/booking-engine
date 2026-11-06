<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/*
 * 2.2: foto delle unità. Upload multipart (JPEG/PNG/WebP, 10 MB, 20 per unità), ricodifica in WebP
 * 1600/400 px senza metadati EXIF (posizione GPS compresa), disco privato, URL firmati di un'ora.
 */

/**
 * JPEG generato con GD e con un segmento APP1 Exif che contiene la posizione GPS (40° 33' 36" N).
 */
function jpegWithGps(int $width = 800, int $height = 600): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 30, 120, 200));
    ob_start();
    imagejpeg($image, null, 90);
    $jpeg = (string) ob_get_clean();

    $entry = fn (int $tag, int $type, int $count, string $value): string => pack('vvV', $tag, $type, $count).str_pad($value, 4, "\0");
    $tiff = 'II'.pack('vV', 42, 8);
    // IFD0 (offset 8): un solo tag, GPSInfo, che punta all'IFD GPS (offset 26).
    $tiff .= pack('v', 1).$entry(0x8825, 4, 1, pack('V', 26)).pack('V', 0);
    // IFD GPS (offset 26): GPSLatitudeRef "N" e GPSLatitude (tre razionali a offset 56).
    $tiff .= pack('v', 2).$entry(0x0001, 2, 2, "N\0").$entry(0x0002, 5, 3, pack('V', 56)).pack('V', 0);
    $tiff .= pack('VVVVVV', 40, 1, 33, 1, 3600, 100);

    $app1 = "Exif\0\0".$tiff;
    $segment = "\xFF\xE1".pack('n', strlen($app1) + 2).$app1;

    return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
}

function pngImage(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

function uploadPhoto(TestCase $test, string $unit, string $bytes, string $name = 'foto.jpg')
{
    return $test->post("/api/v1/admin/units/{$unit}/photos", [
        'photo' => UploadedFile::fake()->createWithContent($name, $bytes),
    ], ['Accept' => 'application/json']);
}

beforeEach(function () {
    // Disco locale vero in una cartella temporanea: Storage::fake non firma gli URL temporanei.
    $this->photoRoot = sys_get_temp_dir().'/photos-test-'.uuid7();
    config(['filesystems.disks.local.root' => $this->photoRoot]);
    Storage::forgetDisk('local');
    $this->tenants = twoTenantsWithUnits();
    $this->a = $this->tenants['a'];
    signedInOwner($this, $this->a['tenant']);
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->photoRoot);
    Storage::forgetDisk('local');
});

it('elimina l\'EXIF con la posizione GPS e salva due misure WebP sul disco privato', function () {
    $source = jpegWithGps();
    $path = tempnam(sys_get_temp_dir(), 'gps');
    file_put_contents($path, $source);
    $exif = exif_read_data($path);
    unlink($path);
    // La foto di prova contiene davvero la posizione.
    expect($exif)->toHaveKey('GPSLatitude')->and($exif['GPSLatitudeRef'])->toBe('N');

    $response = uploadPhoto($this, $this->a['unit'], $source)
        ->assertCreated()
        ->assertJsonPath('position', 0)
        ->assertJsonPath('is_cover', true)
        ->assertJsonPath('width', 800);

    $row = inTenant($this->a['tenant'], fn () => DB::table('unit_photos')->where('id', $response->json('id'))->first());
    expect($row->mime_type)->toBe('image/webp')
        ->and($row->path)->toStartWith("units/{$this->a['tenant']}/{$this->a['unit']}/")
        ->and($row->path)->toEndWith('-1600.webp')
        ->and($row->thumbnail_path)->toEndWith('-400.webp');

    foreach ([$row->path, $row->thumbnail_path] as $stored) {
        $bytes = Storage::disk('local')->get($stored);
        expect(substr($bytes, 0, 4))->toBe('RIFF')
            ->and(substr($bytes, 8, 4))->toBe('WEBP')
            ->and($bytes)->not->toContain('Exif')
            ->and($bytes)->not->toContain('EXIF')
            ->and($bytes)->not->toContain("N\0");
    }

    // La risposta non espone il percorso sul disco, solo URL firmati.
    expect($response->json())->not->toHaveKey('path')
        ->and($response->json('urls.large'))->toContain('signature=')
        ->and($response->json('urls.thumbnail'))->toContain('expires=');
});

it('riduce alla larghezza di 1600 e 400 px senza ingrandire', function () {
    $id = uploadPhoto($this, $this->a['unit'], pngImage(2400, 1200), 'grande.png')->assertCreated()->json('id');
    $row = inTenant($this->a['tenant'], fn () => DB::table('unit_photos')->where('id', $id)->first());

    expect([(int) $row->width, (int) $row->height])->toBe([1600, 800])
        ->and(getimagesizefromstring(Storage::disk('local')->get($row->thumbnail_path))[0])->toBe(400);

    $small = uploadPhoto($this, $this->a['unit'], pngImage(300, 200), 'piccola.png')->assertCreated();
    expect($small->json('width'))->toBe(300);
});

it('accetta solo JPEG, PNG e WebP', function (string $name, string $bytes) {
    uploadPhoto($this, $this->a['unit'], $bytes, $name)
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['details' => ['fields' => ['photo']]]]);
})->with([
    'gif' => ['foto.gif', base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7')],
    'testo con estensione jpg' => ['foto.jpg', 'non sono un\'immagine'],
]);

it('rifiuta file oltre 10 MB', function () {
    $this->post("/api/v1/admin/units/{$this->a['unit']}/photos", [
        'photo' => UploadedFile::fake()->image('enorme.jpg')->size(10241),
    ], ['Accept' => 'application/json'])
        ->assertStatus(400)
        ->assertJsonStructure(['error' => ['details' => ['fields' => ['photo']]]]);
});

it('consente al massimo 20 foto per unità', function () {
    inTenant($this->a['tenant'], function () {
        foreach (range(0, 19) as $i) {
            DB::table('unit_photos')->insert([
                'id' => uuid7(), 'tenant_id' => $this->a['tenant'], 'unit_id' => $this->a['unit'], 'disk' => 'local',
                'path' => "units/x/{$i}.webp", 'mime_type' => 'image/webp', 'size_bytes' => 10, 'position' => $i,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });

    uploadPhoto($this, $this->a['unit'], jpegWithGps(100, 100))
        ->assertStatus(400)
        ->assertJsonStructure(['error' => ['details' => ['fields' => ['photo']]]]);
});

it('serve le foto solo con URL firmato valido', function () {
    $urls = uploadPhoto($this, $this->a['unit'], jpegWithGps(200, 100))->assertCreated()->json('urls');

    $this->get($urls['large'])->assertOk();
    $this->get(explode('?', $urls['large'])[0])->assertForbidden();
    $this->get(str_replace('signature=', 'signature=0', $urls['thumbnail']))->assertForbidden();

    $this->travel(61)->minutes();
    $this->get($urls['large'])->assertForbidden();
});

it('riordina le foto: la prima è la copertina', function () {
    $ids = collect(range(1, 3))->map(fn () => uploadPhoto($this, $this->a['unit'], jpegWithGps(50, 50))->json('id'))->all();
    $reversed = array_reverse($ids);

    $this->putJson("/api/v1/admin/units/{$this->a['unit']}/photos/order", ['photo_ids' => $reversed])
        ->assertOk()
        ->assertJsonPath('data.0.id', $reversed[0])
        ->assertJsonPath('data.0.is_cover', true)
        ->assertJsonPath('data.2.id', $ids[0])
        ->assertJsonPath('data.2.position', 2);

    // L'elenco deve contenere tutte le foto dell'unità.
    $this->putJson("/api/v1/admin/units/{$this->a['unit']}/photos/order", ['photo_ids' => [$ids[0], $ids[1]]])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED');
});

it('elimina una foto, i suoi file e ricompatta le posizioni', function () {
    $ids = collect(range(1, 3))->map(fn () => uploadPhoto($this, $this->a['unit'], jpegWithGps(50, 50))->json('id'))->all();
    $row = inTenant($this->a['tenant'], fn () => DB::table('unit_photos')->where('id', $ids[0])->first());

    $this->deleteJson("/api/v1/admin/units/{$this->a['unit']}/photos/{$ids[0]}")->assertNoContent();

    Storage::disk('local')->assertMissing($row->path);
    Storage::disk('local')->assertMissing($row->thumbnail_path);
    $this->getJson("/api/v1/admin/units/{$this->a['unit']}/photos")
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $ids[1])
        ->assertJsonPath('data.0.position', 0)
        ->assertJsonPath('data.1.position', 1);
});

it('risponde 404 per le foto di un\'unità di un altro tenant', function () {
    $b = $this->tenants['b'];

    $this->getJson("/api/v1/admin/units/{$b['unit']}/photos")->assertNotFound();
    uploadPhoto($this, $b['unit'], jpegWithGps(50, 50))->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    $this->deleteJson("/api/v1/admin/units/{$b['unit']}/photos/".uuid7())->assertNotFound();

    expect(Storage::disk('local')->allFiles())->toBe([]);
});
