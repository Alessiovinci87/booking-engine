<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('payments.simulated.title') }}</title>
    <link rel="stylesheet" href="{{ asset('simulated-checkout.css') }}">
</head>
<body>
<main class="card" data-testid="simulated-checkout">
    <p class="badge">{{ __('payments.simulated.badge') }}</p>
    <h1>{{ __('payments.simulated.title') }}</h1>
    <p class="muted">{{ $session['description'] }}</p>
    <p class="amount">{{ $amount }}</p>

    @if ($session['status'] !== 'open' || $session['expired'])
        <p class="notice">{{ __('payments.simulated.closed') }}</p>
        <a class="button secondary" href="{{ $session['success_url'] }}">{{ __('payments.simulated.back') }}</a>
    @else
        <p class="muted">{{ __('payments.simulated.explanation') }}</p>
        <form method="post" action="{{ route('simulated-checkout.act', [$session['id'], 'pay']) }}">
            @csrf
            <button class="button primary" type="submit">{{ __('payments.simulated.pay') }}</button>
        </form>
        <form method="post" action="{{ route('simulated-checkout.act', [$session['id'], 'decline']) }}">
            @csrf
            <button class="button danger" type="submit">{{ __('payments.simulated.decline') }}</button>
        </form>
        <form method="post" action="{{ route('simulated-checkout.act', [$session['id'], 'abandon']) }}">
            @csrf
            <button class="button secondary" type="submit">{{ __('payments.simulated.abandon') }}</button>
        </form>
    @endif
</main>
</body>
</html>
