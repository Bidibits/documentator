/* ---------- modal, drawer, scrim ---------- */
let modalSave = null, modalReturn = null, drawerReturn = null;

// body is an element; onSave (optional) runs when Save is pressed and may return a promise
function openModal(title, body, onSave, saveLabel){
  const modal = $("#modal");
  if(modal.hidden) modalReturn = document.activeElement;
  $("#m-title").textContent = title;
  $("#m-body").replaceChildren(body);
  $("#m-save").textContent = saveLabel || "Save";
  $("#m-save").hidden = !onSave;
  $("#m-cancel").textContent = onSave ? "Cancel" : "Close";
  modalSave = onSave || null;
  $("#scrim").hidden = false;
  modal.hidden = false;
  $("#m-body").scrollTop = 0;
  const first = $("#m-body").querySelector("input,select,textarea");
  (first || modal).focus();
}

function closeModal(){
  const wasOpen = !$("#modal").hidden;
  $("#modal").hidden = true;
  modalSave = null;
  if($("#drawer").hidden) $("#scrim").hidden = true;
  if(wasOpen && modalReturn && modalReturn.isConnected) modalReturn.focus();
  modalReturn = null;
}

function openDrawer(){
  if($("#drawer").hidden) drawerReturn = document.activeElement;
  $("#scrim").hidden = false;
  $("#drawer").hidden = false;
  $("#drawer").focus();
}

function closeDrawer(){
  const wasOpen = !$("#drawer").hidden;
  $("#drawer").hidden = true;
  if($("#modal").hidden) $("#scrim").hidden = true;
  if(wasOpen && drawerReturn && drawerReturn.isConnected) drawerReturn.focus();
  drawerReturn = null;
}

function closeEverything(){
  $("#modal").hidden = true;
  $("#drawer").hidden = true;
  $("#scrim").hidden = true;
  modalSave = null;
}

async function modalSaveClicked(){
  if(!modalSave) return;
  const button = $("#m-save");
  button.disabled = true;
  try{ await modalSave(); }
  finally{ button.disabled = false; }
}

$("#m-save").addEventListener("click", modalSaveClicked);
$("#m-cancel").addEventListener("click", closeModal);
$("#m-x").addEventListener("click", closeModal);
$("#d-x").addEventListener("click", closeDrawer);
$("#scrim").addEventListener("click", () => { if(!$("#modal").hidden) closeModal(); else closeDrawer(); });
document.addEventListener("keydown", event => {
  if(event.key !== "Escape") return;
  if(!$("#modal").hidden) closeModal();
  else if(!$("#drawer").hidden) closeDrawer();
});
