<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('dev.mail.title') }}</title>
    <link rel="stylesheet" href="{{ asset('dev-mail.css') }}">
</head>
<body>
<header>
    <h1>{{ __('dev.mail.title') }}</h1>
    <p class="muted">{{ __('dev.mail.explanation') }}</p>
    <form method="post" action="{{ route('dev.mail.clear') }}">
        @csrf
        <button type="submit">{{ __('dev.mail.clear') }}</button>
    </form>
</header>
<main data-testid="dev-mail">
    <nav>
        @forelse ($messages as $message)
            <a href="{{ route('dev.mail', ['id' => $message['id']]) }}" @class(['current' => $selected !== null && $selected['id'] === $message['id']]) data-testid="dev-mail-item">
                <strong>{{ $message['subject'] }}</strong>
                <span>{{ implode(', ', $message['to']) }}</span>
                <span class="muted">{{ $message['category'] }} · {{ $message['sent_at'] }}</span>
            </a>
        @empty
            <p class="muted">{{ __('dev.mail.empty') }}</p>
        @endforelse
    </nav>
    @if ($selected !== null)
        <article data-testid="dev-mail-message">
            <dl>
                <dt>{{ __('dev.mail.from') }}</dt><dd>{{ implode(', ', $selected['from']) }}</dd>
                <dt>{{ __('dev.mail.to') }}</dt><dd>{{ implode(', ', $selected['to']) }}</dd>
                @if ($selected['reply_to'] !== [])
                    <dt>{{ __('dev.mail.reply_to') }}</dt><dd>{{ implode(', ', $selected['reply_to']) }}</dd>
                @endif
                <dt>{{ __('dev.mail.subject') }}</dt><dd>{{ $selected['subject'] }}</dd>
                <dt>{{ __('dev.mail.category') }}</dt><dd>{{ $selected['category'] }}</dd>
            </dl>
            <pre>{{ $selected['text'] }}</pre>
        </article>
    @endif
</main>
</body>
</html>
