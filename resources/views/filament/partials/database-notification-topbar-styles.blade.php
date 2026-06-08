{{-- Inline in topbar layout: same tick as HTML, no wait for Vite/head hooks. Pair with databaseNotifications(isLazy: false). --}}
<style>
    div.notificationMenuButton {
        margin-left: 12px;
    }

    div.notificationMenuButton .fi-modal-trigger button {
        background: #fff;
        border: 1px solid #cacaca;
        border-radius: 12px;
        width: 32px;
        height: 32px;
        margin: 0;
        transition: background 0.2s;
    }

    div.notificationMenuButton .fi-modal-trigger button .fi-icon {
        width: 18px;
        height: 18px;
    }

    div.notificationMenuButton .fi-modal-trigger button .fi-icon-btn-badge-ctn {
        border-radius: 50%;
    }

    div.notificationMenuButton .fi-modal-trigger button .fi-icon-btn-badge-ctn .fi-badge {
        background-color: #3366cc;
        color: #fff;
        font-size: 10px;
        font-weight: 700 !important;
        line-height: 1;
        border-radius: 50%;
        padding: 3px 6px;
    }

    div.notificationMenuButton .fi-modal-trigger button:hover {
        background: #cacaca;
    }

    div.notificationMenuButton .fi-modal-trigger button:hover .fi-icon {
        transform: scale(1.1) rotate(15deg);
    }

    /* Chat topbar icon: match database notification button */
    div.notificationMenuButton a.fi-topbar-chat-btn {
        background: #fff;
        border: 1px solid #cacaca;
        border-radius: 12px;
        width: 32px;
        height: 32px;
        margin: 0;
        transition: background 0.2s;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
        box-sizing: border-box;
    }

    div.notificationMenuButton a.fi-topbar-chat-btn .fi-icon {
        width: 18px;
        height: 18px;
    }

    div.notificationMenuButton a.fi-topbar-chat-btn .fi-icon-btn-badge-ctn {
        border-radius: 50%;
    }

    div.notificationMenuButton a.fi-topbar-chat-btn .fi-icon-btn-badge-ctn .fi-badge {
        background-color: #3366cc;
        color: #fff;
        font-size: 10px;
        font-weight: 700 !important;
        line-height: 1;
        border-radius: 50%;
        padding: 3px 6px;
    }

    div.notificationMenuButton a.fi-topbar-chat-btn:hover {
        background: #cacaca;
    }

    div.notificationMenuButton a.fi-topbar-chat-btn:hover .fi-icon {
        transform: scale(1.1) rotate(15deg);
    }
</style>
