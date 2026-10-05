@props(['icon' => 'file-tray-outline', 'title', 'message' => null])
<div class="empty">
    <span class="icon-circle tone-primary"><x-admin.icon :name="$icon" /></span>
    <h3>{{ $title }}</h3>
    @if ($message)<p>{{ $message }}</p>@endif
    {{ $slot }}
</div>
