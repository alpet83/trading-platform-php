<template>
  <div>
    <v-text-field label="Username" v-model="form.username"></v-text-field>
    <v-text-field label="Password" v-model="form.password"></v-text-field>
<!--
    <v-text-field label="Repeat password" v-model="form.repeatPassword"></v-text-field>
-->
    <v-btn @click="save()" block>{{ btnTitle }}</v-btn>
  </div>
</template>

<script>
import {useApiRequest} from "../../composables/api";

export default {
  name: "userForm",
  props: {
    type: {
      default: "create",
      type: String,
    },
    userInfo: {
      default: {},
    },
  },
  mounted() {
    this.form = this.userInfo;
  },
  methods: {
    async save() {
      try {
        if (this.type === "create") {
          await useApiRequest("/external/user", {
            method: "POST",
            body: this.form,
          });
        }
        if (this.type === "update") {
          await useApiRequest(`/external/user/update`, {
            method: "POST",
            body: this.form,
          });
        }

        this.$router.push("/users");
      } catch (e) {
        console.log(e);
      }
    },
  },
  computed: {
    btnTitle() {
      if (this.type === "create") {
        return "Save";
      } else {
        return "Update";
      }
    },
  },
  data() {
    return {
      form: {},
    };
  },
};
</script>

<style scoped></style>
