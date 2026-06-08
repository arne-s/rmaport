@if (filament()->hasUnsavedChangesAlerts())
    @script
        <script>
            (() => {
                let mountedActionsBaseline = null
                let mountedActionsStackDepth = 0

                const hashMountedActions = (actions) =>
                    window.jsMd5(JSON.stringify(actions ?? []).replace(/\\/g, ''))

                const scheduleBaselineCapture = () => {
                    requestAnimationFrame(() => {
                        requestAnimationFrame(() => {
                            const actions = $wire.mountedActions ?? []

                            if (actions.length === 0) {
                                mountedActionsBaseline = null
                                mountedActionsStackDepth = 0

                                return
                            }

                            mountedActionsStackDepth = actions.length
                            mountedActionsBaseline = hashMountedActions(actions)
                        })
                    })
                }

                $wire.$watch('mountedActions', () => {
                    const actions = $wire.mountedActions ?? []

                    if (actions.length === 0) {
                        mountedActionsBaseline = null
                        mountedActionsStackDepth = 0

                        return
                    }

                    if (actions.length !== mountedActionsStackDepth || mountedActionsBaseline === null) {
                        scheduleBaselineCapture()
                    }
                })

                const shouldWarnAboutMountedActions = () => {
                    const actions = $wire.mountedActions ?? []

                    if (actions.length === 0) {
                        return false
                    }

                    if (mountedActionsBaseline === null) {
                        return false
                    }

                    return hashMountedActions(actions) !== mountedActionsBaseline
                }

                window.addEventListener('beforeunload', (event) => {
                    if (typeof @this === 'undefined') {
                        return
                    }

                    if ($wire?.__instance?.effects?.redirect) {
                        return
                    }

                    if (! shouldWarnAboutMountedActions()) {
                        return
                    }

                    event.preventDefault()
                    event.returnValue = true
                })
            })()
        </script>
    @endscript
@endif
