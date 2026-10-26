<a href="{{ url('plugin/panos-vpn') }}">
    <i class="fa fa-shield fa-fw fa-lg" aria-hidden="true"></i>
    Palo Alto VPN
    @if(($down ?? 0) > 0)
        ({{ (int) $down }} down)
    @endif
</a>
