{{-- Sidebar navigasi berbasis aktivitas bisnis (peran-aware, dari NavMenu).
  Fitur: grup collapsible, badge tertunda, cari menu, favorit, terakhir dibuka. --}}
<aside class="navbar navbar-vertical navbar-expand-lg corporate-sidebar d-print-none no-print" data-bs-theme="dark">
  <div class="container-fluid">
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#sidebar-menu" aria-controls="sidebar-menu" aria-expanded="false" aria-label="Menu">
      <span class="navbar-toggler-icon"></span>
    </button>
    <h1 class="navbar-brand navbar-brand-autodark">
      <a href="{{ route('dashboard') }}" class="text-decoration-none d-flex align-items-center gap-2">
        <span class="corp-brandmark">S</span>
        <span class="sidebar-label"><span class="corp-brandname">ServiceHP</span></span>
      </a>
    </h1>
    <div class="collapse navbar-collapse" id="sidebar-menu">
      <div class="px-3 pt-1 pb-2">
        <div class="input-icon">
          <span class="input-icon-addon"><i class="ti ti-search"></i></span>
          <input type="text" id="menu-search" class="form-control form-control-sm" placeholder="Cari menu…" autocomplete="off" aria-label="Cari menu">
        </div>
      </div>
      <ul class="navbar-nav pt-lg-0" id="side-nav">
        <li class="nav-item nav-fav-section" id="nav-favorites" style="display:none">
          <div class="nav-fav-title">Favorit</div>
          <div id="nav-favorites-list"></div>
        </li>
        <li class="nav-item nav-fav-section" id="nav-recents" style="display:none">
          <div class="nav-fav-title">Terakhir dibuka</div>
          <div id="nav-recents-list"></div>
        </li>
        @foreach(($navGroups ?? []) as $gi => $group)
          @php
            $groupOpen = false;
            $groupActive = false;
            foreach ($group['items'] as $it) {
              if (($navRoute ?? null) && (in_array($navRoute, $it['match'] ?? [], true) || $navRoute === ($it['route'] ?? null))) { $groupOpen = true; $groupActive = true; }
            }
          @endphp
          <li class="nav-item dropdown side-group" data-group="{{ $group['label'] }}">
            <a class="nav-link dropdown-toggle {{ $groupActive ? 'active-group' : '' }}" href="#sidebar-group-{{ $gi }}" data-bs-toggle="dropdown" data-bs-auto-close="false" role="button" aria-expanded="{{ $groupOpen ? 'true' : 'false' }}">
              <span class="nav-link-icon d-md-none d-lg-inline-block"><i class="ti {{ $group['icon'] }}"></i></span>
              <span class="nav-link-title sidebar-label">{{ $group['label'] }}</span>
            </a>
            <div class="dropdown-menu {{ $groupOpen ? 'show' : '' }}" id="sidebar-group-{{ $gi }}">
              @foreach($group['items'] as $item)
                @php
                  $url = \App\Support\NavMenu::url($item);
                  $isActive = ($navRoute ?? null) && (in_array($navRoute, $item['match'] ?? [], true) || $navRoute === ($item['route'] ?? null));
                  $badge = isset($item['badge']) ? (int) (($navBadges[$item['badge']] ?? 0)) : 0;
                @endphp
                @if($url)
                <div class="side-item" data-label="{{ strtolower($item['label']) }}">
                  <a class="dropdown-item {{ $isActive ? 'active' : '' }}" href="{{ $url }}" data-route="{{ $item['route'] }}" data-title="{{ $item['label'] }}">
                    <i class="ti {{ $item['icon'] }} me-1"></i><span class="sidebar-label">{{ $item['label'] }}</span>
                    @if($badge > 0)<span class="badge bg-red-lt ms-auto">{{ $badge > 99 ? '99+' : $badge }}</span>@endif
                  </a>
                  <button type="button" class="side-fav" data-route="{{ $item['route'] }}" title="Jadikan favorit" aria-label="Favorit {{ $item['label'] }}"><i class="ti ti-star"></i></button>
                </div>
                @endif
              @endforeach
            </div>
          </li>
        @endforeach
        <li class="nav-item">
          <a class="nav-link" href="{{ route('track.form') }}" target="_blank"><span class="nav-link-icon d-md-none d-lg-inline-block"><i class="ti ti-search"></i></span><span class="nav-link-title sidebar-label">Lacak Service</span></a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="{{ route('help') }}"><span class="nav-link-icon d-md-none d-lg-inline-block"><i class="ti ti-help"></i></span><span class="nav-link-title sidebar-label">Bantuan</span></a>
        </li>
      </ul>
      <div class="sidebar-foot">
        @if(session('active_store_id') && isset($allStores))
          <span class="badge bg-blue-lt w-100 text-start"><i class="ti ti-building-store me-1"></i>{{ optional($allStores->firstWhere('id', session('active_store_id')))->name ?? 'Toko' }}</span>
        @endif
      </div>
    </div>
  </div>
</aside>
