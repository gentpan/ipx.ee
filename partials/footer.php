<?php

declare(strict_types=1);

if (!function_exists('renderSiteFooter')) {
    /**
     * @param array{updated_at:string,next_update_at:string} $xdbMeta
     */
    function renderSiteFooter(array $endpoints, array $xdbMeta, int $year): string
    {
        $metaUpdated = htmlspecialchars((string)($xdbMeta['updated_at'] ?? '—'), ENT_QUOTES, 'UTF-8');
        $nextUpdateAt = htmlspecialchars((string)($xdbMeta['next_update_at'] ?? '—'), ENT_QUOTES, 'UTF-8');

        return <<<HTML
    <section class="footer">
      <div class="xdb-meta xdb-meta-official">
        <span class="xdb-meta-label">官方数据更新公告</span>
        <span class="xdb-note">当前使用 <b>ip2region 付费版数据库</b></span>
        <span class="xdb-sep">·</span>
        <span>上次更新日期：<b>{$metaUpdated}</b></span>
        <span class="xdb-sep">·</span>
        <span>下次更新日期：<b>{$nextUpdateAt}</b></span>
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
