@if ($company && !$company->getHasAgreedTerms())
<script>
    (function() {
        function tryOpenTermsModal() {
            if (window.Livewire?.dispatch) {
                window.Livewire.dispatch('openModal', { component: 'modals.terms-agreement-modal' });
            } else {
                setTimeout(tryOpenTermsModal, 100);
            }
        }

        if (document.readyState === 'complete') {
            setTimeout(tryOpenTermsModal, 200);
        } else {
            window.addEventListener('load', () => setTimeout(tryOpenTermsModal, 200));
        }
    })();
</script>
@endif
