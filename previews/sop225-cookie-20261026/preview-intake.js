document.addEventListener('DOMContentLoaded',()=>{
 document.querySelectorAll('[data-preview-preferences]').forEach(b=>b.addEventListener('click',()=>window.blueHavenCookies.reopen()));
 document.querySelectorAll('form').forEach(form=>{
  const note=document.createElement('p'); note.textContent='Preview only — use test values. No request will be sent to Blue Haven. Turnstile and delivery are not tested.'; note.className='text-sm font-bold mb-4'; form.prepend(note);
  form.addEventListener('submit',async e=>{e.preventDefault();
   const output=form.querySelector('.service-form-success'); output.classList.remove('hidden'); output.setAttribute('role','status'); output.textContent='Recording preview QA receipt…';
   try {
    const session=await fetch('/previews/sop225-cookie-20261026/cookie-consent.php',{credentials:'same-origin',cache:'no-store'}).then(r=>r.json());
    const presence={}; ['first_name','last_name','phone','email','message'].forEach(k=>presence[k]=Boolean(form.elements.namedItem(k)?.value.trim()));
    const response=await fetch('/previews/sop225-cookie-20261026/preview-intake.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:session.csrf,page:location.pathname,presence})});
    const result=await response.json(); if(!response.ok||!result.ok)throw Error();
    output.textContent=result.message+' — QA receipt '+result.qaReceipt+'; preview permission '+result.permission;
   }catch{output.textContent='Preview test could not be recorded. Nothing was sent. Please retry.';}
  });
 });
});
