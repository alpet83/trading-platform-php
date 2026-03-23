export default defineNuxtPlugin((nuxtApp) => {
    nuxtApp.vueApp.directive('click-outside', {
        mounted(el, binding) {
            el.clickOutsideEvent = (event: Event) => {
                setTimeout(() => {
                    if (!(el === event.target || el.contains(event.target))) {
                        binding.value(event);
                    }
                }, 0);
            };
            document.addEventListener('click', el.clickOutsideEvent);
        },
        unmounted(el) {
            document.removeEventListener('click', el.clickOutsideEvent);
        },
    });
});