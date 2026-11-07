/* ---------- modal, drawer, scrim ---------- */
let modalSave = null, modalReturn = null, drawerReturn = null, modalCleanup = null;

// body is an element; onSave (optional) runs when the main button is pressed and may return a promise.
// options: cls (an extra class for the window), cancelLabel (the text of the closing button),
// onClose (runs once when the window closes, for what the body holds, such as a file's blob address)
function openModal(title, body, onSave, saveLabel, options){
  options = options || {};
  const modal = $("#modal");
  runModalCleanup();
  if(modal.hidden) modalReturn = document.activeElement;
  modal.className = "modal" + (options.cls ? " " + options.cls : "");
  modalCleanup = options.onClose || null;
  $("#m-title").textContent = title;
  $("#m-body").replaceChildren(body);
  $("#m-save").textContent = saveLabel || "Save";
  $("#m-save").hidden = !onSave;
  $("#m-cancel").textContent = options.cancelLabel || (onSave ? "Cancel" : "Close");
  modalSave = onSave || null;
  $("#scrim").hidden = false;
  modal.hidden = false;
  $("#m-body").scrollTop = 0;
  const first = $("#m-body").querySelector("input,select,textarea");
  (first || modal).focus();
}

function runModalCleanup(){
  const cleanup = modalCleanup;
  modalCleanup = null;
  if(cleanup) cleanup();
}

function closeModal(){
  const wasOpen = !$("#modal").hidden;
  $("#modal").hidden = true;
  runModalCleanup();
  $("#m-body").replaceChildren();   // a closed viewer must not keep its frame (and the file in it) alive
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
  runModalCleanup();
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
