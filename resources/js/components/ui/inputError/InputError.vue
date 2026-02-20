<!-- InputError.vue -->
<template>
  <div v-if="message" class="text-sm text-red-500">
    {{ message }}
  </div>
</template>

<script setup lang="ts">
const props = defineProps({
  message: {
    type: String,
    default: ''
  }
});
</script>