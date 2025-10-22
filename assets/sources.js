(function () {
  const searchInput = document.getElementById('sourceSearch');
  const filterButtons = Array.from(document.querySelectorAll('.source-filter-group .btn'));
  const rows = Array.from(document.querySelectorAll('tbody#sourcesBody tr.source-row'));
  const details = Array.from(document.querySelectorAll('tbody#sourcesBody tr.source-detail'));
  const emptyRow = document.getElementById('sourcesEmpty');

  function activeFilter() {
    const active = filterButtons.find((btn) => btn.classList.contains('active'));
    return active ? active.getAttribute('data-filter') || 'all' : 'all';
  }

  function hideAllDetails() {
    details.forEach((row) => {
      row.classList.add('d-none');
    });
  }

  function updateVisibility() {
    const query = (searchInput?.value || '').trim().toLowerCase();
    const filter = activeFilter();
    let visibleCount = 0;

    rows.forEach((row) => {
      const enabled = row.getAttribute('data-enabled') === '1';
      const keywords = row.getAttribute('data-keywords') || '';
      const matchesFilter =
        filter === 'all' || (filter === 'enabled' && enabled) || (filter === 'disabled' && !enabled);
      const matchesQuery = !query || keywords.includes(query);
      const visible = matchesFilter && matchesQuery;
      row.classList.toggle('d-none', !visible);
      if (!visible) {
        const sourceId = row.getAttribute('data-source');
        const detail = details.find((d) => d.getAttribute('data-source') === sourceId);
        if (detail) {
          detail.classList.add('d-none');
        }
      } else {
        visibleCount++;
      }
    });

    if (emptyRow) {
      emptyRow.classList.toggle('d-none', visibleCount > 0);
    }
  }

  searchInput?.addEventListener('input', () => {
    updateVisibility();
  });

  filterButtons.forEach((btn) => {
    btn.addEventListener('click', () => {
      filterButtons.forEach((button) => button.classList.remove('active'));
      btn.classList.add('active');
      updateVisibility();
    });
  });

  document.querySelectorAll('.source-detail-toggle').forEach((btn) => {
    btn.addEventListener('click', () => {
      const sourceId = btn.getAttribute('data-source');
      const detail = details.find((row) => row.getAttribute('data-source') === sourceId);
      if (!detail) {
        return;
      }
      const open = !detail.classList.contains('d-none');
      hideAllDetails();
      if (!open) {
        detail.classList.remove('d-none');
        detail.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    });
  });

  updateVisibility();
})();
