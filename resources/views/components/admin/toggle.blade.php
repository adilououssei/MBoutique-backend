@props(['name', 'checked' => false, 'title', 'sub' => null, 'value' => '1'])
<label class="toggle-row">
    <span class="t-text">
        <span class="t-title">{{ $title }}</span>
        @if ($sub)<span class="t-sub" style="display:block">{{ $sub }}</span>@endif
    </span>
    <span class="switch">
        <input type="checkbox" name="{{ $name }}" value="{{ $value }}" @checked($checked) {{ $attributes }}>
        <span></span>
    </span>
</label>
