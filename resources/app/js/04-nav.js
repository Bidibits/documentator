/* ---------- the panels along the top ---------- */
const PAGES = ["documents", "users", "archiving", "audits"];

function pageFromHash(){
  const name = location.hash.replace(/^#/, "");
  return PAGES.includes(name) ? name : "documents";
}

// shows one panel and loads what it holds
function showPage(name){
  if(!PAGES.includes(name)) name = "documents";
  for(const page of PAGES) $("#page-" + page).hidden = page !== name;
  for(const tab of document.querySelectorAll("#tabs .tab")){
    const on = tab.dataset.page === name;
    tab.classList.toggle("on", on);
    if(on) tab.setAttribute("aria-current", "page"); else tab.removeAttribute("aria-current");
  }
  if(location.hash !== "#" + name) history.replaceState(null, "", "#" + name);
  window.scrollTo(0, 0);
  if(name === "documents") loadDocuments();
  if(name === "audits") loadAudits();
}

$("#tabs").addEventListener("click", event => {
  const tab = event.target.closest(".tab");
  if(tab) showPage(tab.dataset.page);
});
