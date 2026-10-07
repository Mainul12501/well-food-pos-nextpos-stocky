<template>
  <div class="main-content">
    <breadcumb :page="$t('WastageTracker')" :folder="$t('WastageTracker')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-if="!isLoading">
      <!-- Filter Bar -->
      <b-card class="mb-4">
        <b-row>
          <b-col md="3" class="mb-3">
            <b-form-group :label="$t('FilterMode')">
              <b-form-radio-group
                v-model="filterMode"
                buttons
                button-variant="outline-primary"
                :options="[
                  {text: $t('Monthly'), value: 'monthly'},
                  {text: $t('CustomRange'), value: 'custom'},
                ]"
              ></b-form-radio-group>
            </b-form-group>
          </b-col>

          <template v-if="filterMode === 'monthly'">
            <b-col md="3" class="mb-3">
              <b-form-group :label="$t('Month')">
                <v-select
                  v-model="selectedMonth"
                  :reduce="label => label.value"
                  :options="months"
                ></v-select>
              </b-form-group>
            </b-col>
            <b-col md="3" class="mb-3">
              <b-form-group :label="$t('Year')">
                <v-select
                  v-model="selectedYear"
                  :reduce="label => label.value"
                  :options="years"
                ></v-select>
              </b-form-group>
            </b-col>
          </template>

          <template v-if="filterMode === 'custom'">
            <b-col md="3" class="mb-3">
              <b-form-group :label="$t('From')">
                <b-form-input type="date" v-model="dateFrom"></b-form-input>
              </b-form-group>
            </b-col>
            <b-col md="3" class="mb-3">
              <b-form-group :label="$t('to')">
                <b-form-input type="date" v-model="dateTo"></b-form-input>
              </b-form-group>
            </b-col>
          </template>

          <b-col md="3" class="mb-3 d-flex align-items-end">
            <b-button variant="primary" @click="fetchData()">
              <i class="i-Filter-2 mr-1"></i> {{ $t("Filter") }}
            </b-button>
          </b-col>
        </b-row>
      </b-card>

      <!-- Summary Cards -->
      <b-row class="mb-4">
        <b-col md="3">
          <b-card class="text-center">
            <h6 class="text-muted">{{ $t('TotalWastage') }}</h6>
            <h3 class="font-weight-bold text-danger">
              {{ currentUser.currency }} {{ total_wastage }}
            </h3>
          </b-card>
        </b-col>
      </b-row>

      <!-- Wastage Returns Table -->
      <b-card :title="$t('WastageReturns')">
        <vue-good-table
          :columns="columns"
          :rows="wastage_returns"
          :search-options="{
            placeholder: $t('Search_this_table'),
            enabled: true,
          }"
          :pagination-options="{
            enabled: true,
            mode: 'records',
            nextLabel: 'next',
            prevLabel: 'prev',
          }"
          styleClass="tableOne table-hover vgt-table"
        >
          <template slot="table-row" slot-scope="props">
            <div v-if="props.column.field == 'statut'">
              <span
                v-if="props.row.statut == 'completed'"
                class="badge badge-outline-success"
              >{{ $t('complete') }}</span>
              <span v-else class="badge badge-outline-info">{{ $t('Pending') }}</span>
            </div>
            <span v-else-if="props.column.field == 'GrandTotal'">
              {{ currentUser.currency }} {{ props.row.GrandTotal }}
            </span>
            <div v-else-if="props.column.field == 'Ref'">
              <router-link :to="'/app/purchase_return/detail/'+props.row.id">
                <span class="ul-btn__text ml-1">{{ props.row.Ref }}</span>
              </router-link>
            </div>
          </template>
        </vue-good-table>
      </b-card>

      <template v-if="canViewWaiver">
        <!-- Waiver balance by supplier -->
        <b-card class="mt-4" :title="$t('WaiverBySupplier') + ' - ' + waiverMonthLabel">
          <!-- This section has its own month and year filter -->
          <b-row>
            <b-col md="3" class="mb-3">
              <b-form-group :label="$t('Month')">
                <v-select
                  v-model="waiverMonth"
                  :reduce="label => label.value"
                  :clearable="false"
                  :options="months"
                ></v-select>
              </b-form-group>
            </b-col>
            <b-col md="3" class="mb-3">
              <b-form-group :label="$t('Year')">
                <v-select
                  v-model="waiverYear"
                  :reduce="label => label.value"
                  :clearable="false"
                  :options="years"
                ></v-select>
              </b-form-group>
            </b-col>
            <b-col md="3" class="mb-3 d-flex align-items-end">
              <b-button variant="primary" @click="fetchWaiverSuppliers()">
                <i class="i-Filter-2 mr-1"></i> {{ $t("Filter") }}
              </b-button>
            </b-col>
          </b-row>

          <vue-good-table
            :columns="waiverSupplierColumns"
            :rows="waiver_suppliers"
            :search-options="{
              placeholder: $t('Search_this_table'),
              enabled: true,
            }"
            :pagination-options="{
              enabled: true,
              mode: 'records',
              nextLabel: 'next',
              prevLabel: 'prev',
            }"
            styleClass="tableOne table-hover vgt-table"
          >
            <template slot="table-row" slot-scope="props">
              <span v-if="['earned', 'used', 'expired', 'balance'].includes(props.column.field)">
                {{ currentUser.currency }} {{ props.row[props.column.field] }}
              </span>
            </template>
          </vue-good-table>
        </b-card>

        <!-- Waiver history with purchase -->
        <b-card class="mt-4" :title="$t('WaiverLedger')">
          <vue-good-table
            :columns="waiverColumns"
            :rows="waiver_transactions"
            :search-options="{
              placeholder: $t('Search_this_table'),
              enabled: true,
            }"
            :pagination-options="{
              enabled: true,
              mode: 'records',
              nextLabel: 'next',
              prevLabel: 'prev',
            }"
            styleClass="tableOne table-hover vgt-table"
          >
            <template slot="table-row" slot-scope="props">
              <div v-if="props.column.field == 'type'">
                <span class="badge" :class="waiverTypeBadge(props.row.type)">{{ waiverTypeLabel(props.row.type) }}</span>
              </div>
              <div v-else-if="props.column.field == 'purchase_ref'">
                <router-link
                  v-if="props.row.purchase_id && !props.row.purchase_deleted"
                  :to="'/app/purchases/detail/'+props.row.purchase_id"
                >
                  <span class="ul-btn__text ml-1">{{ props.row.purchase_ref }}</span>
                </router-link>
                <span v-else>{{ props.row.purchase_ref }}</span>
              </div>
              <span v-else-if="props.column.field == 'rate'">
                {{ props.row.rate === null ? '---' : props.row.rate + '%' }}
              </span>
              <span v-else-if="props.column.field == 'base_amount'">
                {{ props.row.base_amount === null ? '---' : currentUser.currency + ' ' + props.row.base_amount }}
              </span>
              <span
                v-else-if="props.column.field == 'amount'"
                :class="parseFloat(props.row.amount) < 0 ? 'text-danger' : 'text-success'"
              >
                {{ currentUser.currency }} {{ props.row.amount }}
              </span>
            </template>
          </vue-good-table>
        </b-card>
      </template>
    </div>
  </div>
</template>

<script>
import { mapGetters } from "vuex";
import NProgress from "nprogress";

export default {
  metaInfo: {
    title: "Wastage Tracker"
  },

  data() {
    const now = new Date();
    return {
      isLoading: true,
      filterMode: "monthly",
      selectedMonth: now.getMonth() + 1,
      selectedYear: now.getFullYear(),
      // Date range starts as the current month to date
      dateFrom: new Date(now.getFullYear(), now.getMonth(), 2).toISOString().slice(0, 8) + "01",
      dateTo: new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 10),
      wastage_returns: [],
      total_wastage: "0.00",
      waiver_suppliers: [],
      waiverMonth: now.getMonth() + 1,
      waiverYear: now.getFullYear(),
      // Month the supplier table is currently showing
      waiverShownMonth: now.getMonth() + 1,
      waiverShownYear: now.getFullYear(),
      waiver_transactions: [],
    };
  },

  computed: {
    ...mapGetters(["currentUserPermissions", "currentUser"]),

    canViewWaiver() {
      return this.currentUserPermissions && this.currentUserPermissions.includes("Wastage_Waiver_view");
    },

    waiverMonthLabel() {
      const month = this.months.find(item => item.value === this.waiverShownMonth);
      return (month ? month.label : "") + " " + this.waiverShownYear;
    },

    waiverSupplierColumns() {
      return [
        { label: this.$t("Month"), field: "month", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Supplier"), field: "supplier_name", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("WaiverEarned"), field: "earned", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("WaiverUsed"), field: "used", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("WaiverExpired"), field: "expired", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("WaiverBalance"), field: "balance", tdClass: "text-left", thClass: "text-left" },
      ];
    },

    waiverColumns() {
      return [
        { label: this.$t("date"), field: "date", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("type"), field: "type", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Purchase"), field: "purchase_ref", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Supplier"), field: "supplier_name", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Rate"), field: "rate", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("BaseAmount"), field: "base_amount", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Amount"), field: "amount", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("User"), field: "user", tdClass: "text-left", thClass: "text-left" },
      ];
    },

    columns() {
      return [
        {
          label: this.$t("date"),
          field: "date",
          tdClass: "text-left",
          thClass: "text-left"
        },
        {
          label: this.$t("Reference"),
          field: "Ref",
          tdClass: "text-left",
          thClass: "text-left"
        },
        {
          label: this.$t("Supplier"),
          field: "supplier_name",
          tdClass: "text-left",
          thClass: "text-left"
        },
        {
          label: this.$t("warehouse"),
          field: "warehouse_name",
          tdClass: "text-left",
          thClass: "text-left"
        },
        {
          label: this.$t("Total"),
          field: "GrandTotal",
          tdClass: "text-left",
          thClass: "text-left"
        },
        {
          label: this.$t("Status"),
          field: "statut",
          tdClass: "text-left",
          thClass: "text-left"
        },
      ];
    },

    months() {
      return [
        { label: this.$t("January"), value: 1 },
        { label: this.$t("February"), value: 2 },
        { label: this.$t("March"), value: 3 },
        { label: this.$t("April"), value: 4 },
        { label: this.$t("May"), value: 5 },
        { label: this.$t("June"), value: 6 },
        { label: this.$t("July"), value: 7 },
        { label: this.$t("August"), value: 8 },
        { label: this.$t("September"), value: 9 },
        { label: this.$t("October"), value: 10 },
        { label: this.$t("November"), value: 11 },
        { label: this.$t("December"), value: 12 },
      ];
    },

    years() {
      const currentYear = new Date().getFullYear();
      const yearsList = [];
      for (let y = currentYear; y >= currentYear - 5; y--) {
        yearsList.push({ label: String(y), value: y });
      }
      return yearsList;
    },
  },

  methods: {
    fetchData() {
      if (this.filterMode === "custom" && (!this.dateFrom || !this.dateTo || this.dateFrom > this.dateTo)) {
        this.makeToast("warning", this.$t("Please_fill_the_form_correctly"), this.$t("Warning"));
        return;
      }

      this.isLoading = true;
      NProgress.start();
      NProgress.set(0.1);

      let params = {};
      if (this.filterMode === "monthly") {
        params = { month: this.selectedMonth, year: this.selectedYear };
      } else {
        params = { from: this.dateFrom, to: this.dateTo };
      }

      axios
        .get("wastage-tracker", { params })
        .then(response => {
          this.wastage_returns = response.data.wastage_returns;
          this.total_wastage = response.data.total_wastage;
          this.isLoading = false;
          NProgress.done();
        })
        .catch(error => {
          this.isLoading = false;
          NProgress.done();
        });

      // Waiver history for the same filter
      if (this.canViewWaiver) {
        axios
          .get("wastage-waiver", { params })
          .then(response => {
            this.waiver_transactions = response.data.transactions;
          })
          .catch(() => {
            this.waiver_transactions = [];
          });
      }
    },

    // Waiver by supplier, for the month and year chosen in that section
    fetchWaiverSuppliers() {
      if (!this.canViewWaiver) {
        return;
      }
      const month = this.waiverMonth;
      const year = this.waiverYear;

      axios
        .get("wastage-waiver", { params: { month, year } })
        .then(response => {
          this.waiverShownMonth = month;
          this.waiverShownYear = year;
          const label = this.waiverMonthLabel;
          this.waiver_suppliers = response.data.suppliers_summary.map(row => ({ ...row, month: label }));
        })
        .catch(() => {
          this.waiver_suppliers = [];
        });
    },

    waiverTypeLabel(type) {
      const labels = {
        earn: "WaiverTypeEarn",
        earn_reversal: "WaiverTypeEarnReversal",
        redeem: "WaiverTypeRedeem",
        redeem_reversal: "WaiverTypeRedeemReversal",
        expire: "WaiverTypeExpire",
      };
      return labels[type] ? this.$t(labels[type]) : type;
    },

    waiverTypeBadge(type) {
      const badges = {
        earn: "badge-outline-success",
        earn_reversal: "badge-outline-warning",
        redeem: "badge-outline-info",
        redeem_reversal: "badge-outline-warning",
        expire: "badge-outline-danger",
      };
      return badges[type] || "badge-outline-info";
    },

    makeToast(variant, body, title) {
      this.$root.$bvToast.toast(body, {
        title: title,
        variant: variant,
        solid: true
      });
    },
  },

  created() {
    this.fetchData();
    this.fetchWaiverSuppliers();
  },
};
</script>
