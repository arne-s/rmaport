<x-filament-panels::page.simple>
    @if (!$this->success)
        {{ $this->content }}
    @else
        <div class="flash-message success">
            Als het e-mailadres bestaat is er een mail verstuurd met instructies om je wachtwoord te wijzigen.
        </div>
        <div class="reset-instructions" style="font-size:16px; line-height: 26px;">
            <ul>
                <li>Geen reset-mail ontvangen?</li>
                <li>1. Controleer je spam.</li>
                <li>2. Controleer het ingevulde mailadres op spelfouten.</li>
                <li>3. Probeer met een kwartier opnieuw.</li>
            </ul>
        </div>
        <a href="/login" class="backToLoginButton fi-btn">Terug naar inloggen</a>
    @endif
</x-filament-panels::page.simple>
