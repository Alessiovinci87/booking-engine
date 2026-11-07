<?php

namespace App\Http\Controllers;

use App\Domain\Communications\MailPreviewBox;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Casella di anteprima delle email in sviluppo (6.1): /dev/mail. Esiste solo con APP_ENV=local; in
 * ogni altro ambiente risponde 404 anche se la rotta è registrata (la casella non è attiva e non
 * contiene nulla).
 */
final class DevMailController
{
    public function __construct(private readonly MailPreviewBox $box) {}

    public function index(?string $id = null): View
    {
        abort_unless($this->box->enabled(), 404);

        $messages = $this->box->all();
        $selected = $id === null ? ($messages[0] ?? null) : $this->box->find($id);
        abort_if($id !== null && $selected === null, 404);

        return view('dev-mail', ['messages' => $messages, 'selected' => $selected]);
    }

    public function clear(): RedirectResponse
    {
        abort_unless($this->box->enabled(), 404);
        $this->box->clear();

        return redirect()->route('dev.mail');
    }
}
