@auth
    @if (config('filament.broadcasting.echo'))
        <div
            class="fi-chat-echo-listener"
            x-data="{
                channel: null,
                subscribe() {
                    if (! window.Echo || this.channel) {
                        return
                    }

                    this.channel = window.Echo.private('App.Models.User.{{ auth()->id() }}')
                        .listen('.ChatMessageSent', () => {
                            window.dispatchEvent(new CustomEvent('chat-message-received'))
                        })
                },
                init() {
                    if (window.Echo) {
                        this.subscribe()
                    } else {
                        window.addEventListener('EchoLoaded', () => this.subscribe(), { once: true })
                    }
                },
            }"
            x-init="init()"
        ></div>

        <script data-navigate-once>
            document.addEventListener('livewire:init', () => {
                Livewire.hook('request', ({ options }) => {
                    const socketId = window.Echo?.socketId?.()

                    if (socketId) {
                        options.headers['X-Socket-ID'] = socketId
                    } else {
                        delete options.headers['X-Socket-ID']
                    }
                })
            })
        </script>
    @endif
@endauth
