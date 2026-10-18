document.querySelectorAll('[data-secret-endpoint]').forEach(field=>{
    const valueBox=field.querySelector('[data-secret-value]'),show=field.querySelector('[data-secret-show]'),copy=field.querySelector('[data-secret-copy]'),status=field.querySelector('[data-secret-status]');
    const hide=()=>{valueBox.textContent='••••••••••••••••';show.textContent=field.dataset.showLabel;show.setAttribute('aria-pressed','false')};
    const read=async()=>{const response=await fetch(field.dataset.secretEndpoint,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-CSRF-Token':field.dataset.secretCsrf},body:JSON.stringify({id:field.dataset.secretToken})});if(!response.ok)throw Error();const result=await response.json();if(typeof result.value!=='string')throw Error();return result.value};
    const perform=async action=>{show.disabled=copy.disabled=true;status.textContent='';try{await action()}catch{status.textContent=field.dataset.errorLabel}finally{show.disabled=copy.disabled=false}};
    show.addEventListener('click',()=>{if(show.getAttribute('aria-pressed')==='true'){hide();return}perform(async()=>{valueBox.textContent=await read();show.textContent=field.dataset.hideLabel;show.setAttribute('aria-pressed','true')})});
    copy.addEventListener('click',()=>perform(async()=>{const value=await read();try{await navigator.clipboard.writeText(value)}catch{const helper=document.createElement('textarea');helper.value=value;helper.setAttribute('aria-hidden','true');field.append(helper);helper.select();const copied=document.execCommand('copy');helper.remove();copy.focus();if(!copied)throw Error()}status.textContent=field.dataset.copiedLabel}));
    window.addEventListener('pagehide',hide);
    window.addEventListener('pageshow',hide);
});
document.querySelectorAll('form').forEach(form=>{const never=form.elements.namedItem('never'),days=form.elements.namedItem('days');if(!never||!days)return;const update=()=>{days.disabled=never.checked};never.addEventListener('change',update);update()});
