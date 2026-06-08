@if (!($hide ?? false))
    <li class="{{ request()->routeIs($route) ? 'active' : '' }}">
        <a href="{{route($route)}}" style="background-image: url({{ asset($icon) }})">
            {{ $label }}
        </a>
    </li>
@endif
