const form = document.querySelector('#setup-form');
const steps = [...form.querySelectorAll('[data-step]')];
const progress = [...document.querySelectorAll('.setup-progress li')];
const back = document.querySelector('#setup-back');
const next = document.querySelector('#setup-next');
const submit = document.querySelector('#setup-submit');
let current = 0;
form.noValidate = true;

function showStep(index, focus = true) {
  current = index;
  steps.forEach((step, i) => { step.hidden = i !== index; });
  progress.forEach((item, i) => {
    if (i === index) item.setAttribute('aria-current', 'step');
    else item.removeAttribute('aria-current');
  });
  back.hidden = index === 0;
  next.hidden = index === steps.length - 1;
  submit.hidden = index !== steps.length - 1;
  document.querySelector('#setup-step-label').textContent = `Step ${index + 1} of ${steps.length}`;
  if (focus) steps[index].querySelector('h2').focus();
}
function validateStep(index) {
  const invalid = [...steps[index].querySelectorAll('input')].find(input => !input.validity.valid);
  if (!invalid) return true;
  showStep(index, false);
  const details = invalid.closest('details');
  if (details) details.open = true;
  invalid.reportValidity();
  return false;
}
next.addEventListener('click', () => {
  if (validateStep(current)) showStep(current + 1);
});
back.addEventListener('click', () => showStep(current - 1));
form.addEventListener('submit', event => {
  if (current !== steps.length - 1) {
    event.preventDefault();
    if (validateStep(current)) showStep(current + 1);
    return;
  }
  for (let i = 0; i < steps.length; i++) {
    if (!validateStep(i)) {
      event.preventDefault();
      return;
    }
  }
  submit.disabled = true;
  submit.textContent = 'Setting up…';
});
// Passwords are cleared after server errors; return to Application to re-enter them.
showStep(document.querySelector('.setup-error') ? 1 : 0, false);
