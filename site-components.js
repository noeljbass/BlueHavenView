(function () {
  const navLink = 'text-slate-200 hover:text-white px-3 py-2 text-sm font-bold rounded-lg hover:bg-white/10 transition-colors';
  const dropdownLink = 'block px-4 py-3 text-sm font-semibold text-slate-700 hover:text-blue-800 hover:bg-blue-50 rounded-xl transition-colors';
  const footerLink = 'text-slate-400 hover:text-white text-sm transition-colors';

  const services = [
    ['Window Replacement', '/window-replacement.html'],
    ['Energy Efficient Windows', '/energy-efficient-windows.html'],
    ['Window Installation', '/window-installation.html'],
    ['Custom Window Styles', '/custom-window-styles.html'],
    ['Lifetime Warranty Windows', '/lifetime-warranty-windows.html'],
    ['Replacement Window ROI', '/replacement-window-roi.html']
  ];
  const serviceAreas = [
    ['All Service Areas', '/service-areas/'],
    ['Franklin, TN', '/window-replacement.html'],
    ['Brentwood, TN', '/service-areas/brentwood-tn.html'],
    ['Nashville, TN', '/service-areas/nashville-tn.html'],
    ['Murfreesboro, TN', '/service-areas/murfreesboro-tn.html'],
    ['Spring Hill, TN', '/service-areas/spring-hill-tn.html'],
    ['Nolensville, TN', '/service-areas/nolensville-tn.html'],
    ['Thompson\'s Station, TN', '/service-areas/thompsons-station-tn.html'],
    ['Columbia, TN', '/service-areas/columbia-tn.html'],
    ['Clarksville, TN', '/service-areas/clarksville-tn.html']
  ];
  const resources = [
    ['Window Guides', '/window-guides/'],
    ['Free Buyer\'s Guide', '/funnel.html'],
    ['Case Studies', '/trust/case-studies.html'],
    ['Project Gallery', '/trust/project-gallery.html'],
    ['Meet Stephen', '/trust/meet-stephen.html'],
    ['Why Homeowners Choose Us', '/trust/why-homeowners-choose-us.html']
  ];

  function menu(label, items) {
    return `<div class="relative group">
      <button class="${navLink} inline-flex items-center gap-1" type="button" aria-haspopup="true">${label}<span aria-hidden="true">⌄</span></button>
      <div class="absolute left-0 top-full z-50 hidden w-72 pt-3 group-hover:block group-focus-within:block">
        <div class="rounded-2xl border border-slate-200 bg-white p-3 shadow-2xl">${items.map(([text, href]) => `<a class="${dropdownLink}" href="${href}">${text}</a>`).join('')}</div>
      </div>
    </div>`;
  }

  function header() {
    return `<nav id="site-header" class="sticky top-0 z-50 bg-slate-950/95 text-white shadow-xl shadow-slate-950/20 backdrop-blur supports-[backdrop-filter]:bg-slate-950/85">
      <div class="mx-auto flex h-20 max-w-7xl items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
        <a href="/" class="flex min-w-0 items-center gap-3" aria-label="Blue Haven Windows home">
          <img src="/logo.png" alt="Blue Haven Windows logo" class="h-10 w-auto object-contain">
          <span class="leading-tight"><span class="block text-base font-black tracking-tight sm:text-lg">Blue Haven Windows</span><span class="hidden text-xs font-bold text-blue-200 sm:block">Better Windows. Better Life.</span></span>
        </a>
        <div class="hidden items-center gap-1 lg:flex">
          ${menu('Services', services)}
          ${menu('Service Areas', serviceAreas)}
          ${menu('Resources', resources)}
          <a class="${navLink}" href="/trust/why-blue-haven.html">Why Blue Haven</a>
          <a class="${navLink}" href="/index.html#contact">Contact</a>
          <a href="/window-replacement.html#page-contact" class="ml-3 rounded-xl bg-blue-700 px-5 py-3 text-sm font-black text-white shadow-lg shadow-blue-950/30 transition hover:bg-blue-600">Get Free Estimate</a>
        </div>
        <div class="flex items-center gap-2 lg:hidden">
          <a href="/window-replacement.html#page-contact" class="rounded-xl bg-blue-700 px-4 py-3 text-xs font-black text-white shadow-lg shadow-blue-950/30">Get Free Estimate</a>
          <button id="site-menu-toggle" class="rounded-xl border border-white/15 px-3 py-2 text-sm font-black text-white" type="button" aria-controls="site-mobile-menu" aria-expanded="false">Menu</button>
        </div>
      </div>
      <div id="site-mobile-menu" class="hidden border-t border-white/10 bg-slate-950 px-4 pb-5 pt-3 lg:hidden">
        <div class="mx-auto grid max-w-7xl gap-5 sm:grid-cols-3">
          <div><p class="mb-2 text-xs font-black uppercase tracking-wide text-blue-200">Services</p>${services.map(([t,h])=>`<a class="block py-2 text-sm font-semibold text-slate-200" href="${h}">${t}</a>`).join('')}</div>
          <div><p class="mb-2 text-xs font-black uppercase tracking-wide text-blue-200">Service Areas</p>${serviceAreas.map(([t,h])=>`<a class="block py-2 text-sm font-semibold text-slate-200" href="${h}">${t}</a>`).join('')}</div>
          <div><p class="mb-2 text-xs font-black uppercase tracking-wide text-blue-200">Resources</p>${resources.map(([t,h])=>`<a class="block py-2 text-sm font-semibold text-slate-200" href="${h}">${t}</a>`).join('')}<a class="block py-2 text-sm font-semibold text-slate-200" href="/index.html#contact">Contact</a></div>
        </div>
      </div>
    </nav>`;
  }

  function footer() {
    return `<footer id="site-footer" class="bg-slate-950 text-white">
      <div class="mx-auto max-w-7xl px-6 py-14">
        <div class="grid gap-10 md:grid-cols-2 lg:grid-cols-4">
          <div><a href="/" class="flex items-center gap-3"><img src="/logo.png" alt="Blue Haven Windows logo" class="h-10 w-auto"><span class="font-black">Blue Haven Windows<span class="block text-xs text-blue-300">Better Windows. Better Life.</span></span></a><p class="mt-4 text-sm leading-7 text-slate-400">Local, family-owned replacement window guidance for Middle Tennessee homeowners.</p></div>
          <div><h2 class="mb-4 font-black">Services</h2>${services.slice(0,5).map(([t,h])=>`<a class="${footerLink} block py-1.5" href="${h}">${t}</a>`).join('')}</div>
          <div><h2 class="mb-4 font-black">Service Areas</h2>${serviceAreas.slice(0,6).map(([t,h])=>`<a class="${footerLink} block py-1.5" href="${h}">${t}</a>`).join('')}</div>
          <div><h2 class="mb-4 font-black">Resources</h2>${resources.slice(0,5).map(([t,h])=>`<a class="${footerLink} block py-1.5" href="${h}">${t}</a>`).join('')}<a class="${footerLink} block py-1.5" href="/privacy-policy.html">Privacy Policy</a><a class="${footerLink} block py-1.5" href="/terms-of-service.html">Terms of Service</a></div>
        </div>
        <div class="mt-10 flex flex-col gap-4 border-t border-white/10 pt-6 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between"><p>&copy; 2026 Blue Haven Windows. All rights reserved. Franklin, TN.</p><p><a class="hover:text-white" href="tel:+16159870593">(615) 987-0593</a> · <a class="hover:text-white" href="/window-replacement.html#page-contact">Get Free Estimate</a></p></div>
      </div>
    </footer>`;
  }

  function removeOldTopNav() {
    const nav = document.querySelector('nav#navbar');
    if (nav) nav.remove();
    document.querySelectorAll('header.hero, header').forEach((headerEl) => {
      const first = headerEl.firstElementChild;
      if (first && first.querySelector('img[alt*="Blue Haven Windows"], a[href*="index.html"], a[href="/"]')) first.remove();
    });
  }

  function install() {
    removeOldTopNav();
    document.body.insertAdjacentHTML('afterbegin', header());
    const oldFooter = document.querySelector('footer');
    if (oldFooter) oldFooter.outerHTML = footer();
    else document.body.insertAdjacentHTML('beforeend', footer());
    const toggle = document.getElementById('site-menu-toggle');
    const mobile = document.getElementById('site-mobile-menu');
    if (toggle && mobile) toggle.addEventListener('click', () => {
      const open = mobile.classList.toggle('hidden') === false;
      toggle.setAttribute('aria-expanded', String(open));
    });
    if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install);
  else install();
})();
