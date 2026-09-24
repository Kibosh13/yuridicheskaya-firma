const search = document.getElementById('section-search');
if (search) {
  search.addEventListener('input', () => {
    const query = search.value.trim().toLocaleLowerCase('ru');
    document.querySelectorAll('[data-searchable]').forEach(section => {
      section.hidden = query !== '' && !section.dataset.searchable.toLocaleLowerCase('ru').includes(query);
    });
  });
}

document.querySelectorAll('.sidebar-nav a').forEach(link => {
  link.addEventListener('click', () => {
    const target = document.querySelector(link.getAttribute('href'));
    if (target?.hidden) {
      search.value = '';
      document.querySelectorAll('[data-searchable]').forEach(section => { section.hidden = false; });
    }
  });
});

const leadFilters = document.querySelectorAll('[data-lead-filter]');
leadFilters.forEach(button => {
  button.addEventListener('click', () => {
    const status = button.dataset.leadFilter;
    leadFilters.forEach(item => item.classList.toggle('active', item === button));
    document.querySelectorAll('[data-lead-status]').forEach(card => {
      card.hidden = status !== 'all' && card.dataset.leadStatus !== status;
    });
  });
});
