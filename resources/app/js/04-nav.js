/* ---------- the side bar: panels, and a rail that folds in and out ---------- */
const PAGES = ["documents", "expiring", "users", "roles", "departments", "archiving", "audits"];
// what each panel needs (the server refuses it otherwise; this decides what is shown)
const PAGE_RIGHTS = {documents: "documentator.document.view", expiring: "documentator.document.view", users: "documentator.user.manage", roles: "documentator.role.manage", departments: "documentator.department.manage", archiving: "documentator.version.archive", audits: "documentator.audit.view"};
const PAGE_TITLES = {documents: "Documents", expiring: "Expiring", users: "Users", roles: "Roles", departments: "Departments", archiving: "Archiving", audits: "Audits"};

function pageFromHash(){
  const name = location.hash.replace(/^#/, "");
  return PAGES.includes(name) && can(PAGE_RIGHTS[name]) ? name : PAGES.find(p => can(PAGE_RIGHTS[p])) || "documents";
}

// shows one panel and loads what it holds
function showPage(name){
  if(!PAGES.includes(name) || !can(PAGE_RIGHTS[name])) name = pageFromHash();
  for(const page of PAGES) $("#page-" + page).hidden = page !== name;
  for(const item of document.querySelectorAll("#tabs .nav-item")){
    if(item.dataset.page === name) item.setAttribute("aria-current", "page"); else item.removeAttribute("aria-current");
  }
  $("#tb-title").textContent = PAGE_TITLES[name];
  if(location.hash !== "#" + name) history.replaceState(null, "", "#" + name);
  $("#main").scrollTop = 0;
  window.scrollTo(0, 0);
  closeNav();
  if(name === "documents") loadDocuments();
  if(name === "expiring") loadExpiring(1);
  if(name === "audits") loadAudits();
  if(name === "archiving") loadArchive();
  if(name === "users") loadUsers();
  if(name === "departments") loadDepartments();
  if(name === "roles") loadRoles();
}

// the wide screen's side bar folds to icons and back; the choice is kept in this browser
function updateRailButton(){
  const folded = document.documentElement.getAttribute("data-rail") === "1";
  const button = $("#rail-btn");
  const label = folded ? "Expand the sidebar" : "Collapse the sidebar";
  button.setAttribute("aria-label", label);
  button.title = label;
  button.setAttribute("aria-pressed", String(folded));
}
function toggleRail(){
  const folded = document.documentElement.getAttribute("data-rail") === "1";
  if(folded) document.documentElement.removeAttribute("data-rail"); else document.documentElement.setAttribute("data-rail", "1");
  try{ localStorage.setItem("documentator.rail.v1", folded ? "0" : "1"); }catch(e){}
  updateRailButton();
}

// on a narrow screen the side bar slides over the page
function openNav(){
  document.documentElement.setAttribute("data-nav", "open");
  $("#nav-scrim").hidden = false;
  const current = document.querySelector("#tabs .nav-item[aria-current]");
  if(current) current.focus();
}
function closeNav(){
  if(document.documentElement.getAttribute("data-nav") !== "open") return;
  document.documentElement.removeAttribute("data-nav");
  $("#nav-scrim").hidden = true;
}

$("#tabs").addEventListener("click", event => {
  const item = event.target.closest(".nav-item");
  if(item) showPage(item.dataset.page);
});
$("#rail-btn").addEventListener("click", toggleRail);
$("#nav-open").addEventListener("click", openNav);
$("#nav-scrim").addEventListener("click", closeNav);
document.addEventListener("keydown", event => { if(event.key === "Escape") closeNav(); });
updateRailButton();
