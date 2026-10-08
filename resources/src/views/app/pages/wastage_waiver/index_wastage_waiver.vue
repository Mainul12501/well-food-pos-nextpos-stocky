<template>
  <div class="main-content">
    <breadcumb :page="$t('WaiverAccount')" :folder="$t('WastageWaiver')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-if="!isLoading">
      <!-- Filter Bar -->
      <b-card class="mb-4">
        <b-row>
          <b-col md="3" class="mb-3">
            <b-form-group class="mb-0" :label="$t('Supplier')">
              <v-select
                v-model="selectedSupplier"
                :reduce="label => label.value"
                :placeholder="$t('AllSuppliers')"
                :options="suppliers.map(supplier => ({label: supplier.name, value: supplier.id}))"
              ></v-select>
            </b-form-group>
          </b-col>

          <b-col md="2" class="mb-3">
            <b-form-group class="mb-0" :label="$t('FilterMode')">
              <v-select
                v-model="filterMode"
                :reduce="label => label.value"
                :clearable="false"
                :options="[
                  {label: $t('Monthly'), value: 'monthly'},
                  {label: $t('CustomRange'), value: 'custom'},
                ]"
              ></v-select>
            </b-form-group>
          </b-col>

          <template v-if="filterMode === 'monthly'">
            <b-col md="2" class="mb-3">
              <b-form-group class="mb-0" :label="$t('Month')">
                <v-select
                  v-model="selectedMonth"
                  :reduce="label => label.value"
                  :clearable="false"
                  :options="months"
                ></v-select>
              </b-form-group>
            </b-col>
            <b-col md="2" class="mb-3">
              <b-form-group class="mb-0" :label="$t('Year')">
                <v-select
                  v-model="selectedYear"
                  :reduce="label => label.value"
                  :clearable="false"
                  :options="years"
                ></v-select>
              </b-form-group>
            </b-col>
          </template>

          <template v-if="filterMode === 'custom'">
            <b-col md="2" class="mb-3">
              <b-form-group class="mb-0" :label="$t('From')">
                <b-form-input type="date" v-model="dateFrom"></b-form-input>
              </b-form-group>
            </b-col>
            <b-col md="2" class="mb-3">
              <b-form-group class="mb-0" :label="$t('To')">
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
            <h6 class="text-muted">{{ $t('WaiverBalance') }}</h6>
            <h3 class="font-weight-bold text-success">
              {{ currentUser.currency }} {{ current_balance }}
            </h3>
          </b-card>
        </b-col>
        <b-col md="3">
          <b-card class="text-center">
            <h6 class="text-muted">{{ $t('WaiverEarned') }} ({{ waiver_rate }}%)</h6>
            <h3 class="font-weight-bold text-info">
              {{ currentUser.currency }} {{ earned }}
            </h3>
          </b-card>
        </b-col>
        <b-col md="3">
          <b-card class="text-center">
            <h6 class="text-muted">{{ $t('WaiverUsed') }}</h6>
            <h3 class="font-weight-bold text-warning">
              {{ currentUser.currency }} {{ used }}
            </h3>
          </b-card>
        </b-col>
        <b-col md="3">
          <b-card class="text-center">
            <h6 class="text-muted">{{ $t('WaiverExpired') }}</h6>
            <h3 class="font-weight-bold text-danger">
              {{ currentUser.currency }} {{ expired }}
            </h3>
          </b-card>
        </b-col>
      </b-row>

      <!-- Waiver by Supplier -->
      <b-card class="mb-4" :title="$t('WaiverBySupplier') + ' - ' + waiverMonthLabel">
        <!-- This section has its own month and year filter -->
        <b-row>
          <b-col md="3" class="mb-3">
            <b-form-group class="mb-0" :label="$t('Month')">
              <v-select
                v-model="waiverMonth"
                :reduce="label => label.value"
                :clearable="false"
                :options="months"
              ></v-select>
            </b-form-group>
          </b-col>
          <b-col md="3" class="mb-3">
            <b-form-group class="mb-0" :label="$t('Year')">
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
          :columns="supplierColumns"
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

      <!-- Waiver Ledger -->
      <b-card :title="$t('WaiverLedger')">
        <vue-good-table
          :columns="columns"
          :rows="transactions"
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
              <span class="badge" :class="typeBadge(props.row.type)">{{ typeLabel(props.row.type) }}</span>
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
    </div>
  </div>
</template>

<script>
import { mapGetters } from "vuex";
import NProgress from "nprogress";

export default {
  metaInfo: {
    title: "Wastage Waiver"
  },

  data() {
    const now = new Date();
    return {
      isLoading: true,
      filterMode: "monthly",
      selectedSupplier: null,
      selectedMonth: now.getMonth() + 1,
      selectedYear: now.getFullYear(),
      dateFrom: "",
      dateTo: "",
      suppliers: [],
      transactions: [],
      waiver_suppliers: [],
      waiverMonth: now.getMonth() + 1,
      waiverYear: now.getFullYear(),
      // Month the supplier table is currently showing
      waiverShownMonth: now.getMonth() + 1,
      waiverShownYear: now.getFullYear(),
      current_balance: "0.00",
      earned: "0.00",
      used: "0.00",
      expired: "0.00",
      waiver_rate: 5,
    };
  },

  computed: {
    ...mapGetters(["currentUserPermissions", "currentUser"]),

    waiverMonthLabel() {
      const month = this.months.find(item => item.value === this.waiverShownMonth);
      return (month ? month.label : "") + " " + this.waiverShownYear;
    },

    supplierColumns() {
      return [
        {
          label: this.$t("Month"),
          field: "month",
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
          label: this.$t("WaiverEarned"),
          field: "earned",
          tdClass: "text-left",
          thClass: "text-left"
        },
        {
          label: this.$t("WaiverUsed"),
          field: "used",
          tdClass: "text-left",
          thClass: "text-left"
        },
        {
          label: this.$t("WaiverExpired"),
          field: "expired",
          tdClass: "text-left",
          thClass: "text-left"
        },
        {
          label: this.$t("WaiverBalance"),
          field: "balance",
          tdClass: "text-left",
          thClass: "text-left"
        },
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
          label: this.$t("type"),
          field: "type",
          tdClass: "text-left",
          thClass: "text-left"
        },
        {
          label: this.$t("Purchase"),
          field: "purchase_ref",
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
          label: this.$t("Rate"),
          field: "rate",
          tdClass: "text-left",
          thClass: "text-left"
        },
        {
          label: this.$t("BaseAmount"),
          field: "base_amount",
          tdClass: "text-left",
          thClass: "text-left"
        },
        {
          label: this.$t("Amount"),
          field: "amount",
          tdClass: "text-left",
          thClass: "text-left"
        },
        {
          label: this.$t("User"),
          field: "user",
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
      this.isLoading = true;
      NProgress.start();
      NProgress.set(0.1);

      let params = {};
      if (this.filterMode === "monthly") {
        params = { month: this.selectedMonth, year: this.selectedYear };
      } else {
        params = { from: this.dateFrom, to: this.dateTo };
      }
      if (this.selectedSupplier) {
        params.provider_id = this.selectedSupplier;
      }

      axios
        .get("wastage-waiver", { params })
        .then(response => {
          this.transactions = response.data.transactions;
          this.suppliers = response.data.suppliers;
          this.current_balance = response.data.current_balance;
          this.earned = response.data.earned;
          this.used = response.data.used;
          this.expired = response.data.expired;
          this.waiver_rate = response.data.waiver_rate;
          this.isLoading = false;
          NProgress.done();
        })
        .catch(error => {
          this.isLoading = false;
          NProgress.done();
        });
    },

    // Waiver by supplier, for the month and year chosen in that section
    fetchWaiverSuppliers() {
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

    typeLabel(type) {
      const labels = {
        earn: "WaiverTypeEarn",
        earn_reversal: "WaiverTypeEarnReversal",
        redeem: "WaiverTypeRedeem",
        redeem_reversal: "WaiverTypeRedeemReversal",
        expire: "WaiverTypeExpire",
      };
      return labels[type] ? this.$t(labels[type]) : type;
    },

    typeBadge(type) {
      const badges = {
        earn: "badge-outline-success",
        earn_reversal: "badge-outline-warning",
        redeem: "badge-outline-info",
        redeem_reversal: "badge-outline-warning",
        expire: "badge-outline-danger",
      };
      return badges[type] || "badge-outline-info";
    },
  },

  created() {
    this.fetchData();
    this.fetchWaiverSuppliers();
  },
};
</script>
