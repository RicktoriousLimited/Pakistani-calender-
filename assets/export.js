async function currentFilters(){
  return {
    q: document.getElementById('q').value,
    area: document.getElementById('area').value,
    feeder: document.getElementById('feeder').value,
    date: document.getElementById('date').value,
    division: document.getElementById('division').value
  };
}
document.getElementById('btnCsv').addEventListener('click', async ()=>{
  const f=await currentFilters(); const qs=new URLSearchParams({route:'export',format:'csv',...f}).toString();
  window.open(`api.php?${qs}`,'_blank');
});
document.getElementById('btnIcs').addEventListener('click', async ()=>{
  const f=await currentFilters(); const qs=new URLSearchParams({route:'export',format:'ics',...f}).toString();
  window.open(`api.php?${qs}`,'_blank');
});
document.getElementById('btnPdf').addEventListener('click', async ()=>{
  const { jsPDF } = window.jspdf;
  const doc = new jsPDF({unit:'pt', format:'a4'});
  doc.setFontSize(14); doc.text('Shutdown Report', 40, 40);
  doc.setFontSize(9);
  const text = document.getElementById('grouped').innerText || 'No items';
  const lines = doc.splitTextToSize(text, 515);
  doc.text(lines, 40, 70);
  doc.save('shutdown-report.pdf');
});
