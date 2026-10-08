<template>
  <button
    v-if="currentUser && currentUser.is_owner"
    type="button"
    class="btn btn-outline-primary btn-sm mr-2"
    :disabled="loading"
    :title="label"
    @click="confirmSync"
  >
    <i class="i-Reload"></i>
    <span class="btn-text">{{ label }}</span>
  </button>
</template>

<script>
import { mapGetters } from "vuex";

export default {
  name: "SyncOwnerPermissionsButton",

  data() {
    return { loading: false };
  },

  computed: {
    ...mapGetters(["currentUser"]),

    label() {
      return this.tr("Sync_Owner_Permissions", "Sync Permissions");
    }
  },

  methods: {
    // translations live in the DB; fall back to English until a key is added
    tr(key, fallback, values) {
      return this.$te(key) ? this.$t(key, values) : fallback.replace("{count}", values ? values.count : "");
    },

    confirmSync() {
      this.$swal({
        title: this.label,
        text: this.tr(
          "Sync_Owner_Permissions_Confirm",
          "Assign all missing permissions to the Owner role?"
        ),
        type: "question",
        showCancelButton: true,
        confirmButtonText: this.tr("Yes", "Yes"),
        cancelButtonText: this.tr("Cancel", "Cancel")
      }).then(result => {
        if (result.value) {
          this.sync();
        }
      });
    },

    sync() {
      this.loading = true;
      axios
        .post("roles/sync_owner_permissions")
        .then(({ data }) => {
          const message = data.added_count
            ? this.tr("Owner_Permissions_Synced", "{count} permission(s) added to the Owner role", {
                count: data.added_count
              })
            : this.tr("Owner_Permissions_Up_To_Date", "Owner role is already up to date");
          this.$root.$bvToast.toast(message, {
            title: this.tr("Success", "Success"),
            variant: "success",
            solid: true
          });
          if (data.added_count) {
            // permissions are cached in the store; reload to pick them up
            setTimeout(() => window.location.reload(), 1200);
          }
        })
        .catch(() => {
          this.$root.$bvToast.toast(this.tr("InvalidData", "Something went wrong"), {
            title: this.tr("Failed", "Failed"),
            variant: "danger",
            solid: true
          });
        })
        .finally(() => {
          this.loading = false;
        });
    }
  }
};
</script>
