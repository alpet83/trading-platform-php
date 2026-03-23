<template>
  <div>
    <v-btn @click="goTo">Create user</v-btn>
    <v-table fixed-header>
      <thead>
        <tr>
          <th v-for="element in headers">
            {{ element.text }}
          </th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="item in users">
          <td>{{ item.username }}</td>
          <td>
           <nuxt-link :to="'/users/update?id=' + item.id"
              ><v-icon icon="mdi-pencil"></v-icon></nuxt-link
            ><v-icon @click="deleteItem(item)" icon="mdi-close-circle"></v-icon>
          </td>
        </tr>
      </tbody>
      <v-pagination
        v-model="page"
        :length="totalPage"
        :total-visible="7"
      ></v-pagination>
    </v-table>
  </div>
</template>

<script>
import { useApiRequest } from "~/composables/api";
export default {
  name: "index",
  async beforeMount() {
    const { data } = await useApiRequest("/external/user", {
      method: "GET",
      query: {
        limit: 20,
        offset: 0,
      },
    });
    this.users = data.value.users;
    this.totalPage = Math.ceil(data.value.count / 20);
  },
  methods: {
    goTo () {
      this.$router.push('/users/create')
    },
    async getUser() {
      const { data } = await useApiRequest("/external/user", {
        method: "GET",
        query: {
          limit: 20,
          offset: (this.page - 1) * 20,
        },
      });
      this.users = data.value.users;
      this.totalPage = Math.ceil(data.value.count / 20);
    },
    async deleteItem(item) {
      const confirmation = window.confirm(`Are you sure you want to delete the user ${item.name}?`);

      if (confirmation) {
        try {
          await useApiRequest(`/external/user/${item.id}`, { method: "DELETE" });
          const index = this.users.findIndex((e) => e.id === item.id);
          if (index !== -1) {
            this.users.splice(index, 1);
          }
        } catch (e) {
          console.log(e);
        }
      } else {
        console.log('Deletion canceled');
      }
    }
  },
  watch: {
    page() {
      this.getUser();
    }
  },
  data() {
    return {
      users: [],
      headers: [
        { text: "Email", value: "email", sortable: false },
        { text: "Actions", value: "actions", sortable: false },
      ],
      page: 1,
      totalPage: 1,
    };
  },
};
</script>

<style scoped></style>
