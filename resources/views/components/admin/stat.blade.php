@props(['label', 'value', 'icon', 'tone' => 'primary', 'foot' => null])
<div class="card stat">
    <div class="stat-top">
        <span class="stat-label">{{ $label }}</span>
        <span class="icon-circle tone-{{ $tone }}"><x-admin.icon :name="$icon" /></span>
    </div>
    <div class="stat-value">{{ $value }}</div>
    @if ($foot)<div class="stat-foot">{{ $foot }}</div>@endif
</div>
