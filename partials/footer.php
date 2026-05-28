<?php

declare(strict_types=1);

if (!function_exists('renderSiteFooter')) {
    /**
     * @param array{provider:string,note:string} $geoSource
     */
    function renderSiteFooter(array $endpoints, array $geoSource, int $year): string
    {
        $provider = htmlspecialchars((string)($geoSource['provider'] ?? 'GeoIP provider'), ENT_QUOTES, 'UTF-8');
        $note = htmlspecialchars((string)($geoSource['note'] ?? ''), ENT_QUOTES, 'UTF-8');

        return <<<HTML
    <section class="footer">
      <div class="xdb-meta xdb-meta-official">
        <span class="xdb-meta-label">GeoIP 数据源</span>
        <span class="xdb-note">当前使用 <b>{$provider}</b></span>
        <span class="xdb-sep">·</span>
        <span>{$note}</span>
      </div>
    </section>

    <footer class="site-footer">
      <div class="site-footer-inner">
        <span class="site-copyright">© {$year} <a class="copyright-brand" href="https://www.bluecdn.com/" target="_blank" rel="noreferrer">Bluecdn.com</a> All rights reserved.</span>
        <div class="site-socials" aria-label="Social links">
          <a href="https://x.com/bluecdn" target="_blank" rel="noreferrer" aria-label="X">
            <i class="fa-brands fa-x-twitter" aria-hidden="true"></i>
          </a>
          <a href="https://github.com/bluecdn" target="_blank" rel="noreferrer" aria-label="GitHub">
            <i class="fa-brands fa-github" aria-hidden="true"></i>
          </a>
          <a href="https://www.linkedin.com/company/bluecdn/" target="_blank" rel="noreferrer" aria-label="LinkedIn">
            <i class="fa-brands fa-linkedin-in" aria-hidden="true"></i>
          </a>
          <a href="https://t.me/bluecdn" target="_blank" rel="noreferrer" aria-label="Telegram">
            <i class="fa-brands fa-telegram" aria-hidden="true"></i>
          </a>
        </div>
      </div>
    </footer>
HTML;
    }
}
