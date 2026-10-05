@props(['name', 'size' => null])
<i {{ $attributes->merge(['class' => 'ion']) }} @if ($size) style="font-size: {{ $size }}px" @endif aria-hidden="true">{{ \App\Modules\Admin\Support\Icons::glyph($name) }}</i>
