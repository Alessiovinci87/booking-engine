<?php

namespace App\Domain\Communications;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Casella di anteprima delle email in sviluppo (6.1): un file JSON per messaggio in
 * storage/app/mail-preview, consultabile su /dev/mail. Attiva solo con APP_ENV=local, mai in
 * testing, staging o produzione. Tiene gli ultimi `limit` messaggi e non oltre 30 giorni
 * (categoria G di 09).
 */
final class MailPreviewBox
{
    private const MAX_AGE_DAYS = 30;

    public function __construct(
        private readonly Application $app,
        private readonly Filesystem $files,
        private readonly string $directory,
        private readonly int $limit = 200,
    ) {}

    public function enabled(): bool
    {
        return $this->app->environment('local') && (bool) config('mail.preview.enabled', true);
    }

    public function store(Email $email, ?string $category): string
    {
        $this->files->ensureDirectoryExists($this->directory);
        $id = (string) Str::ulid();

        $this->files->put($this->path($id), (string) json_encode([
            'id' => $id,
            'sent_at' => Carbon::now('UTC')->toIso8601String(),
            'category' => $category,
            'from' => array_map(fn (Address $a): string => $a->toString(), $email->getFrom()),
            'to' => array_map(fn (Address $a): string => $a->toString(), $email->getTo()),
            'reply_to' => array_map(fn (Address $a): string => $a->toString(), $email->getReplyTo()),
            'subject' => (string) $email->getSubject(),
            'text' => (string) $email->getTextBody(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        $this->prune();

        return $id;
    }

    /**
     * Messaggi dal più recente.
     *
     * @return list<array{id: string, sent_at: string, category: ?string, from: list<string>, to: list<string>, reply_to: list<string>, subject: string, text: string}>
     */
    public function all(): array
    {
        $messages = [];

        foreach ($this->paths() as $path) {
            $decoded = json_decode((string) $this->files->get($path), true);

            if (is_array($decoded)) {
                /** @var array{id: string, sent_at: string, category: ?string, from: list<string>, to: list<string>, reply_to: list<string>, subject: string, text: string} $decoded */
                $messages[] = $decoded;
            }
        }

        return $messages;
    }

    /**
     * @return array{id: string, sent_at: string, category: ?string, from: list<string>, to: list<string>, reply_to: list<string>, subject: string, text: string}|null
     */
    public function find(string $id): ?array
    {
        if (! Str::isUlid($id) || ! $this->files->exists($this->path($id))) {
            return null;
        }

        /** @var array{id: string, sent_at: string, category: ?string, from: list<string>, to: list<string>, reply_to: list<string>, subject: string, text: string}|null $decoded */
        $decoded = json_decode((string) $this->files->get($this->path($id)), true);

        return is_array($decoded) ? $decoded : null;
    }

    public function clear(): void
    {
        foreach ($this->paths() as $path) {
            $this->files->delete($path);
        }
    }

    private function prune(): void
    {
        // Orologio di sistema, lo stesso delle date dei file.
        $cutoff = time() - self::MAX_AGE_DAYS * 86400;

        foreach ($this->paths() as $position => $path) {
            if ($position >= $this->limit || $this->files->lastModified($path) < $cutoff) {
                $this->files->delete($path);
            }
        }
    }

    /**
     * File dal più recente: il nome è un ULID, ordinabile nel tempo.
     *
     * @return list<string>
     */
    private function paths(): array
    {
        if (! $this->files->isDirectory($this->directory)) {
            return [];
        }

        $paths = $this->files->glob($this->directory.DIRECTORY_SEPARATOR.'*.json');
        rsort($paths);

        return $paths;
    }

    private function path(string $id): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.$id.'.json';
    }
}
