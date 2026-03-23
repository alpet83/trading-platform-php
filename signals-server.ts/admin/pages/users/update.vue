<template>
  <user-form
    v-if="form?.id"
    :type="'update'"
    :user-info="form"
  ></user-form>
</template>

<script>
import UserForm from "../../component/form/userForm";
import {useApiRequest} from "../../composables/api";

export default {
  name: "update",
  components: { UserForm },
  async beforeMount() {
    const { data } = await useApiRequest(
      `/external/user/${this.$route.query.id}`,
      { method: "GET" }
    );
    this.form = data.value
  },
  data() {
    return {
      form: {},
    };
  },
};
</script>

<style scoped></style>
