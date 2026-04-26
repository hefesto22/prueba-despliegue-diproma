{{-- F6a.5 — Badge de sucursal activa en topbar (montado vía render hook). --}}
{{-- Montado dentro de .fi-topbar-end vía GLOBAL_SEARCH_AFTER, por lo que   --}}
{{-- hereda el flex gap del contenedor sin necesidad de clases propias.    --}}
<div class="fi-topbar-establishment-switcher">
    {{ $this->switchAction }}
</div>
