
        lucide.createIcons();
        const backToTop = document.getElementById('back-to-top');
        window.addEventListener('scroll', () => { if (window.scrollY > 600) { backToTop.classList.remove('opacity-0','translate-y-4','pointer-events-none'); backToTop.classList.add('opacity-100','translate-y-0'); } else { backToTop.classList.add('opacity-0','translate-y-4','pointer-events-none'); backToTop.classList.remove('opacity-100','translate-y-0'); } });
        backToTop.addEventListener('click', () => { window.scrollTo({ top: 0, behavior: 'smooth' }); });
        const observer = new IntersectionObserver((entries) => { entries.forEach(entry => { if (entry.isIntersecting) { entry.target.classList.add('revealed'); observer.unobserve(entry.target); } }); }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });
        document.querySelectorAll('.scroll-reveal').forEach(el => observer.observe(el));
