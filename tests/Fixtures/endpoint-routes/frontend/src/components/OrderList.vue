<template><div /></template>
<script>
export default {
    methods: {
        load(id) {
            return fetch(`/api/v1/items/${id}`);
        },
        search() {
            return fetch("/task/search/");
        },
    },
};
</script>
