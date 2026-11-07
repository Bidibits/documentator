try{
  var t = localStorage.getItem("documentator.theme.v1");
  if(t === "light" || t === "dark") document.documentElement.setAttribute("data-theme", t);
}catch(e){}
