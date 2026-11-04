
        lucide.createIcons();
        const backToTop = document.getElementById('back-to-top');
        window.addEventListener('scroll', () => { if (window.scrollY > 600) { backToTop.classList.remove('opacity-0','translate-y-4','pointer-events-none'); backToTop.classList.add('opacity-100','translate-y-0'); } else { backToTop.classList.add('opacity-0','translate-y-4','pointer-events-none'); backToTop.classList.remove('opacity-100','translate-y-0'); } });
        backToTop.addEventListener('click', () => { window.scrollTo({ top: 0, behavior: 'smooth' }); });
        const observer = new IntersectionObserver((entries) => { entries.forEach(entry => { if (entry.isIntersecting) { entry.target.classList.add('revealed'); observer.unobserve(entry.target); } }); }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });
        document.querySelectorAll('.scroll-reveal').forEach(el => observer.observe(el));


      (function () {
        const callHref = '#preview-disabled';
        const callText = '(615) 987-0593';

        function installAdsHeader() {
          const header = document.getElementById('site-header');
          if (!header) return;

          const desktopActions = header.querySelector('.hidden.items-center.gap-1.lg\\:flex');
          if (desktopActions) {
            desktopActions.className = 'hidden items-center gap-4 lg:flex';
            desktopActions.innerHTML = '<a href="' + callHref + '" data-ads-call data-tracking-key="franklin-header-desktop-call" class="text-sm font-bold text-white hover:text-blue-200 transition-colors">Call ' + callText + '</a><a href="#page-contact" data-tracking-key="franklin-header-desktop-estimate" class="rounded-xl bg-blue-700 px-5 py-3 text-sm font-black text-white shadow-lg shadow-blue-950/30 transition hover:bg-blue-600">Get Free Estimate</a>';
          }

          const mobileActions = header.querySelector('.flex.items-center.gap-2.lg\\:hidden');
          if (mobileActions) {
            mobileActions.innerHTML = '<a href="' + callHref + '" data-ads-call data-tracking-key="franklin-header-mobile-call" class="px-2 py-3 text-xs font-black text-white">Call</a><a href="#page-contact" data-tracking-key="franklin-header-mobile-estimate" class="rounded-xl bg-blue-700 px-4 py-3 text-xs font-black text-white shadow-lg shadow-blue-950/30">Get Free Estimate</a>';
          }

          const mobileMenu = document.getElementById('site-mobile-menu');
          if (mobileMenu) mobileMenu.remove();
          document.querySelectorAll('a[href^="tel:"]').forEach(function (link) { link.setAttribute('data-ads-call', ''); });
        }

        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', installAdsHeader);
        else installAdsHeader();
      }());
    

      (function () {
        function measureClearance() {
          const bar = document.querySelector('body > .fixed.bottom-0.md\\:hidden');
          if (bar) document.body.style.setProperty('--mobile-estimate-bar-height', Math.ceil(bar.getBoundingClientRect().height) + 'px');
          const header = document.getElementById('site-header');
          if (header) document.body.style.setProperty('--estimate-header-clearance', Math.ceil(header.getBoundingClientRect().height) + 16 + 'px');
        }
        function installClearance() {
          measureClearance();
          if (window.ResizeObserver) {
            const observer = new ResizeObserver(measureClearance);
            const bar = document.querySelector('body > .fixed.bottom-0.md\\:hidden');
            const header = document.getElementById('site-header');
            if (bar) observer.observe(bar);
            if (header) observer.observe(header);
          }
          window.addEventListener('resize', measureClearance);
        }
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', installClearance);
        else installClearance();
      }());
    