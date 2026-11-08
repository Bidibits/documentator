/* ---------- the server ---------- */
// The one place the page talks to the API. The X-Documentator header is what the server checks in place of a CSRF
// token. A 401 from anywhere but the sign-in itself means the server no longer knows this browser.
let onSessionEnded = null;
let onPasswordNeeded = null;   // the server wants this person to choose a new password before anything else

async function send(method, path, body){
  const options = {method: method, headers: {"X-Documentator": "1", "Accept": "application/json"}, credentials: "same-origin", cache: "no-store"};
  if(body instanceof FormData){
    options.body = body;   // the browser adds the multipart boundary itself
  }else if(body !== undefined){
    options.headers["Content-Type"] = "application/json";
    options.body = JSON.stringify(body);
  }

  let response;
  try{ response = await fetch(path, options); }
  catch(e){ const err = new Error("offline"); err.code = "offline"; err.status = 0; err.data = {}; throw err; }

  if(!response.ok){
    let data = null;
    try{ data = await response.json(); }catch(e){ data = null; }
    const err = new Error((data && data.error) || ("http_" + response.status));
    err.code = (data && data.error) || ("http_" + response.status);
    err.status = response.status;
    err.data = data || {};
    if(response.status === 401 && path !== "/api/login" && onSessionEnded) onSessionEnded();
    if(response.status === 403 && err.code === "password_change_required" && onPasswordNeeded) onPasswordNeeded();
    throw err;
  }
  return response;
}

async function api(method, path, body){
  const response = await send(method, path, body);
  let data = null;
  try{ data = await response.json(); }catch(e){ data = null; }
  return data || {};
}

// a file's bytes, fetched with the header (a plain link or <iframe src> could not send it)
async function apiBlob(path){
  return (await send("GET", path)).blob();
}

// a file handed to the browser as a download
async function apiDownload(path, name){
  const blob = await apiBlob(path);
  const url = URL.createObjectURL(blob);
  const link = h("a", {href: url, download: name});
  document.body.append(link);
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(url), 10000);
}
