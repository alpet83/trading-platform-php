<template>
  <user-form :type="'create'"></user-form>
</template>

<script>
import UserForm from "../../component/form/userForm";

export default {
  name: "create",
  components: { UserForm },
};
</script>

<style scoped></style>
