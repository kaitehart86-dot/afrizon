(function(){
function apply(v){document.documentElement.setAttribute('data-pv-theme', v==='dark'?'dark':'light');}
try{apply(localStorage.getItem('pv_theme'));}catch(e){}
window.addEventListener('storage',function(e){ if(e.key==='pv_theme') apply(e.newValue); });
document.addEventListener('pv:theme',function(e){ apply(e.detail&&e.detail.theme); });
})();