<?php

declare(strict_types=1);

if (!function_exists('renderSiteHeader')) {
    function renderSiteHeader(string $context = 'home'): string
    {
        $isApi = $context === 'api';
        $isAbout = $context === 'about';
        $isContact = $context === 'contact';
        $homeClass = (!$isApi && !$isAbout && !$isContact) ? 'nav-link is-active' : 'nav-link';
        $aboutClass = $isAbout ? 'nav-link is-active' : 'nav-link';
        $apiClass = $isApi ? 'nav-link is-active' : 'nav-link';
        $contactClass = $isContact ? 'nav-link is-active' : 'nav-link';

        return <<<HTML
  <nav class="top-nav">
    <div class="top-nav-inner">
      <div>
        <a href="/" class="brand-link" aria-label="ip.bluecdn.com">
          <svg t="1772241833459" class="brand-logo" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg" p-id="13684" width="24" height="24" fill="currentColor" aria-hidden="true">
            <path d="M582.3 918H182.1c-41.2-0.3-74.5-35.8-74.5-79.5V186.9c0-43.9 33.6-79.4 74.9-79.4h662.1c41.2 0.3 74.5 35.7 74.5 79.4v434c0 7.7-5.9 14-13.2 14-7.3 0-13.2-6.3-13.2-14v-434c-0.1-28.3-21.8-51.3-48.5-51.3H182.1c-26.6 0.3-48 23.2-48.1 51.3v651.7c0 28.4 21.7 51.4 48.5 51.4h399.7c7.3 0 13.2 6.3 13.2 14 0.1 7.8-5.8 14-13.1 14z" p-id="13685"></path>
            <path d="M582.3 927.4H182.1c-46.1-0.3-83.3-40-83.3-88.8V186.9c0.1-49 37.6-88.7 83.8-88.8h662.1c46 0.3 83.2 39.9 83.3 88.8v434c0 12.9-9.9 23.4-22 23.4s-22-10.5-22-23.4v-434c0-23.2-17.8-42-39.7-42H182.1c-21.7 0.3-39.2 19-39.2 42v651.7c0 23.2 17.8 42 39.7 42.1h399.7c12.2 0 22 10.5 22 23.4 0 12.8-9.8 23.3-22 23.3zM182.1 117c-36.2 0.3-65.5 31.4-65.7 69.9v651.7c0 38.7 29.6 70.1 66.1 70.1h399.7c2.4 0 4.4-2.1 4.4-4.7s-2-4.7-4.4-4.7H182.1c-31.5-0.3-56.8-27.4-56.9-60.8V186.9c0-33.5 25.7-60.7 57.3-60.7h662.1c31.4 0.3 56.8 27.3 56.9 60.7v434c0 2.6 2 4.7 4.4 4.7 2.4 0 4.4-2.1 4.4-4.7v-434c0-38.7-29.6-70-66.1-70l-662.1 0.1z" p-id="13686"></path>
            <path d="M729.1 869.6c-63.3 0-114.6-54.4-114.6-121.6s51.3-121.6 114.6-121.6S843.7 680.9 843.7 748c0 67.2-51.3 121.6-114.6 121.6z m0-214.4c-48.7 0-88.2 41.9-88.2 93.5s39.5 93.5 88.2 93.5 88.2-41.9 88.2-93.5c0.1-51.6-39.3-93.6-88-93.7h-0.2v0.2z" p-id="13687"></path>
            <path d="M876.4 927.7c-3.7 0-7.3-1.7-9.8-4.6l-76.5-88.9c-5.5-5.1-6-14-1.2-19.8 4.8-5.8 13.2-6.4 18.7-1.3 0.8 0.7 1.4 1.5 2 2.4l76.5 88.8c4.9 5.7 4.6 14.6-0.8 19.8-2.4 2.4-5.6 3.6-8.9 3.6zM423.6 340.7v315.6h-82.7V340.7h82.7z m146.8 315.7h-80.7V340.7h117.9C712.3 339.6 764 372 762.7 438c-2.8 61.3-46.9 93.8-132.2 97.3h-60l-0.1 121.1z m0-262.8v88.8h24.8c55.1 4.6 82-10.2 80.7-44.3 0-30.8-26.4-45.5-80.7-44.4l-24.8-0.1z" p-id="13688"></path>
          </svg>
        </a>
      </div>

      <div class="nav-menu" data-nav-track>
        <span class="nav-indicator" aria-hidden="true"></span>
        <a class="{$homeClass}" data-nav-item href="/">首页</a>
        <a class="{$aboutClass}" data-nav-item href="/about">关于</a>
        <a class="{$apiClass}" data-nav-item href="/api">接口</a>
        <a class="{$contactClass}" data-nav-item href="/contact">联系</a>
      </div>

      <button class="nav-toggle" aria-label="Toggle menu" type="button">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M4 12h16"></path>
          <path d="M4 18h16"></path>
          <path d="M4 6h16"></path>
        </svg>
      </button>
    </div>
  </nav>
  <script>
    (function () {
      var menus = document.querySelectorAll('[data-nav-track]');
      if (!menus.length) return;

      menus.forEach(function (menu) {
        var indicator = menu.querySelector('.nav-indicator');
        var items = menu.querySelectorAll('[data-nav-item]');
        if (!indicator || !items.length) return;

        var active = menu.querySelector('[data-nav-item].is-active') || items[0];

        function moveTo(el) {
          if (!el) return;
          indicator.style.width = el.offsetWidth + 'px';
          indicator.style.height = el.offsetHeight + 'px';
          indicator.style.transform = 'translate(' + el.offsetLeft + 'px,' + el.offsetTop + 'px)';
          indicator.style.opacity = '1';
        }

        moveTo(active);

        items.forEach(function (item) {
          item.addEventListener('mouseenter', function () { moveTo(item); });
          item.addEventListener('focus', function () { moveTo(item); });
        });

        menu.addEventListener('mouseleave', function () { moveTo(active); });
        menu.addEventListener('focusout', function (event) {
          if (!menu.contains(event.relatedTarget)) moveTo(active);
        });

        window.addEventListener('resize', function () { moveTo(active); });
      });
    })();
  </script>
HTML;
    }
}
