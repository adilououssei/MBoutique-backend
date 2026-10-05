@props(['tone' => 'neutral', 'icon' => null])
<span {{ $attributes->merge(['class' => "badge tone-{$tone}"]) }}>@if ($icon)<x-admin.icon :name="$icon" />@endif{{ $slot }}</span>
