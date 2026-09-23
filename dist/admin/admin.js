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
