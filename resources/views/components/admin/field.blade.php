@props(['name', 'label', 'required' => false, 'help' => null])
<div class="field">
    <label for="{{ $name }}">{{ $label }} @if ($required)<span class="req">*</span>@endif</label>
    {{ $slot }}
    @error($name)<span class="error">{{ $message }}</span>@enderror
    @if ($help)<span class="help">{{ $help }}</span>@endif
</div>
