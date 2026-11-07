/* ---------- upload a document ---------- */
// The code is only suggested (TSI-YY-MM-DD-NN): the person may keep it, edit it or type anything else.
const FILE_TYPES = ".pdf,.docx,.png,.jpg,.jpeg";

function formField(name, label, control, hint, required){
  return h("div", {class: "f"},
    h("label", {for: "fld-" + name}, label, required ? h("span", {class: "req", "aria-hidden": "true"}, "*") : null),
    control,
    hint ? h("span", {class: "hint"}, hint) : null);
}

async function openUpload(){
  let suggested = "", suggestFailed = null;
  try{ suggested = (await api("GET", "/api/documents/suggest-code")).code || ""; }
  catch(e){ if(e.status === 401) return; suggestFailed = e; }
  fillSuggestionLists();

  const error = h("div", {class: "banner error", role: "alert", hidden: true}, icon("alert-circle"), h("span", {}));
  const code = h("input", {id: "fld-code", name: "code", type: "text", maxlength: "64", value: suggested, autocomplete: "off", spellcheck: "false"});
  const useSuggested = h("button", {class: "btn sm", type: "button", hidden: true}, "Use suggestion");
  const title = h("input", {id: "fld-title", name: "title", type: "text", maxlength: "200", autocomplete: "off"});
  const type = h("input", {id: "fld-doc_type", name: "doc_type", type: "text", maxlength: "64", list: "dl-types", autocomplete: "off", placeholder: "Choose from the list or type your own"});
  const department = h("input", {id: "fld-department", name: "department", type: "text", maxlength: "100", list: "dl-depts", autocomplete: "off", placeholder: docs.departments.length ? "Choose from the list or type your own" : "Type the department"});
  const issued = h("input", {id: "fld-issued_on", name: "issued_on", type: "date", value: todayISO()});
  const expires = h("input", {id: "fld-expires_on", name: "expires_on", type: "date"});
  const location = h("input", {id: "fld-location_note", name: "location_note", type: "text", maxlength: "200", autocomplete: "off"});
  const description = h("textarea", {id: "fld-description", name: "description", maxlength: "2000"});
  const files = h("input", {id: "fld-files", name: "files", type: "file", multiple: true, accept: FILE_TYPES});
  const picked = h("ul", {class: "picked"});

  const syncSuggestion = () => { useSuggested.hidden = !suggested || code.value === suggested; };
  code.addEventListener("input", syncSuggestion);
  useSuggested.addEventListener("click", () => { code.value = suggested; syncSuggestion(); code.focus(); });
  files.addEventListener("change", () => {
    picked.replaceChildren(...[...files.files].map(f => h("li", {}, h("span", {}, f.name), h("span", {}, fmtSize(f.size)))));
  });

  const show = text => { error.querySelector("span").textContent = text; error.hidden = false; error.scrollIntoView({block: "nearest"}); };
  const fail = (text, field) => { show(text); if(field) field.focus(); };

  const body = h("form", {novalidate: true, onsubmit: e => e.preventDefault()},
    error,
    formField("code", "Code", h("div", {class: "inline"}, code, useSuggested),
      "Suggested as TSI-year-month-day-number. Keep it, change it, or type any code you like, for example the number on the paper.", true),
    formField("title", "Title", title, null, true),
    h("div", {class: "grid2"},
      formField("doc_type", "Type", type, "Pick one from the list, or type a new kind."),
      formField("department", "Department", department, "Which department this belongs to.")),
    h("div", {class: "grid2"},
      formField("issued_on", "Issued on", issued),
      formField("expires_on", "Expires on", expires, "Leave empty if it does not expire.")),
    formField("location_note", "Physical location", location, "Where the paper copy is kept, for example Cabinet 3, shelf B. Just a note for now."),
    formField("description", "Description", description),
    h("div", {class: "fieldset-h"}, "Files"),
    formField("files", "Files", files,
      "PDF, Word (.docx), PNG or JPG. Up to 10 files of 25 MB each. Several files make one document with one code, like a stapled packet.", true),
    picked);

  const save = async () => {
    error.hidden = true;
    const codeText = code.value.trim();
    if(!codeText) return fail("Enter a code for this document.", code);
    if(!title.value.trim()) return fail("Enter a title.", title);
    if(files.files.length === 0) return fail("Choose at least one file.", files);
    if(expires.value && issued.value && expires.value < issued.value) return fail("The expiry date cannot be before the issue date.", expires);

    const form = new FormData();
    form.append("code", codeText);
    form.append("title", title.value.trim());
    for(const [key, input] of [["doc_type", type], ["department", department], ["issued_on", issued], ["expires_on", expires],
                               ["location_note", location], ["description", description]]){
      if(input.value.trim() !== "") form.append(key, input.value.trim());
    }
    for(const file of files.files) form.append("files[]", file);

    try{
      const result = await api("POST", "/api/documents", form);
      closeModal();
      toast("Registered " + result.document.code);
      await loadDocuments();
      openDetail(result.document.id);
    }catch(e){
      if(e.status === 401) return;
      uploadError(e, {code: code, title: title, files: files, expires_on: expires, issued_on: issued}, suggestion => {
        suggested = suggestion;
        code.value = suggestion;
        syncSuggestion();
      }, fail);
    }
  };

  openModal("Upload document", body, save, "Upload");
  syncSuggestion();
  if(suggestFailed){
    show(suggestFailed.code === "offline"
      ? "The server did not answer, so no code could be suggested. Check the connection; you can also type a code yourself."
      : "No code could be suggested. You can type a code yourself.");
  }
}

function uploadError(e, fields, takeSuggestion, fail){
  if(e.code === "code_taken"){
    if(e.data.suggestion){
      takeSuggestion(e.data.suggestion);
      return fail("That code was taken a moment ago. The next free one is filled in; press Upload again.", fields.code);
    }
    return fail("Another document already uses that code. Choose a different one.", fields.code);
  }
  if(e.code === "invalid"){
    const field = e.data.field;
    if(field === "files") return fail(e.data.message === "type"
      ? "One of the files is not a PDF, Word (.docx), PNG or JPG file."
      : "A file could not be uploaded. Each file may be up to 25 MB, and at most 10 files.", fields.files);
    if(field === "expires_on") return fail("The expiry date cannot be before the issue date.", fields.expires_on);
    if(field === "code") return fail("The code can be up to 64 characters and cannot contain line breaks.", fields.code);
    if(field === "title") return fail("Enter a title of up to 200 characters.", fields.title);
    return fail("Check the form: one of the fields is too long or not valid.");
  }
  if(e.code === "too_large") return fail("The upload is too big. Send fewer or smaller files.", fields.files);
  return fail(errorWords(e));
}
