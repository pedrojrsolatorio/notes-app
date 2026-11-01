function noteBackupWorkflow() {
    return {
        busy: null,
        error: "",
        fieldErrors: {},
        invalidItems: [],
        exportStatus: "",
        previewStatus: "",
        preview: null,
        previewToken: "",
        expiresAt: "",
        result: null,
        entityLabels: {
            folders: "Folders",
            notes: "Notes",
            attachments: "Attachments",
        },
        reasonLabels: {
            note_already_exists: "A note with this backup ID already exists. The existing note was not changed.",
            portable_id_exists: "An attachment with this backup ID already exists.",
        },

        async sendForm(form, url) {
            const response = await fetch(url, {
                method: "POST",
                body: new FormData(form),
                credentials: "same-origin",
                headers: {
                    Accept: "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                },
            });

            return this.readResponse(response);
        },

        async sendData(data, url) {
            const response = await fetch(url, {
                method: "POST",
                body: data,
                credentials: "same-origin",
                headers: {
                    Accept: "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                },
            });

            return this.readResponse(response);
        },

        async readResponse(response) {
            const contentType = response.headers.get("content-type") ?? "";
            const payload = contentType.includes("application/json")
                ? await response.json()
                : { message: "The request could not be completed." };

            if (!response.ok) {
                const error = new Error(payload.message ?? "The request could not be completed.");
                error.payload = payload;
                error.status = response.status;

                throw error;
            }

            return { response, payload };
        },

        clearMessages() {
            this.error = "";
            this.fieldErrors = {};
            this.invalidItems = [];
        },

        showFailure(error) {
            this.error = error.message;
            this.fieldErrors = error.payload?.errors ?? {};
            this.invalidItems = error.payload?.items ?? [];

            if (error.payload?.code === "preview_expired") {
                this.preview = null;
                this.previewToken = "";
                this.expiresAt = "";
                this.previewStatus = "This preview expired. Upload the archive again.";
            }
        },

        async exportBackup(form) {
            this.clearMessages();
            this.previewStatus = "";
            this.result = null;
            this.busy = "export";
            this.exportStatus = "Preparing your encrypted backup.";

            try {
                const { response } = await this.sendForm(form, form.action);
                const archive = await response.blob();
                const downloadUrl = URL.createObjectURL(archive);
                const downloadLink = document.createElement("a");

                downloadLink.href = downloadUrl;
                downloadLink.download = "notes-backup.zip";
                downloadLink.click();
                window.setTimeout(() => URL.revokeObjectURL(downloadUrl), 1000);
                form.reset();
                this.exportStatus = "Your backup is ready.";
            } catch (error) {
                form.reset();
                this.showFailure(error);
                this.exportStatus = "";
            } finally {
                this.busy = null;
            }
        },

        async previewBackup(form) {
            this.clearMessages();
            this.preview = null;
            this.previewToken = "";
            this.expiresAt = "";
            this.result = null;
            this.busy = "preview";
            this.previewStatus = "Validating your backup.";

            try {
                const { payload } = await this.sendForm(form, form.action);
                form.reset();
                this.previewToken = payload.preview_token;
                this.expiresAt = payload.expires_at;
                this.preview = payload;
                this.previewStatus = "Your backup is ready to review.";
            } catch (error) {
                form.reset();
                this.showFailure(error);
                this.previewStatus = "";
            } finally {
                this.busy = null;
            }
        },

        async restoreBackup(form) {
            this.clearMessages();
            this.busy = "restore";
            this.previewStatus = "Restoring your notes and attachments.";

            try {
                const { payload } = await this.sendForm(form, form.action);
                form.reset();
                this.result = payload;
                this.preview = null;
                this.previewToken = "";
                this.expiresAt = "";
                this.previewStatus = "Restore complete.";
            } catch (error) {
                form.reset();
                this.showFailure(error);
                this.previewStatus = "";
            } finally {
                this.busy = null;
            }
        },

        async cancelPreview() {
            this.clearMessages();
            this.busy = "cancel";

            const formData = new FormData();
            const csrfToken = this.$refs.restoreForm.querySelector('input[name="_token"]').value;
            formData.set("_token", csrfToken);
            formData.set("preview_token", this.previewToken);

            try {
                await this.sendData(formData, this.$refs.restoreForm.dataset.cancelUrl);
                this.preview = null;
                this.previewToken = "";
                this.expiresAt = "";
                this.$refs.restoreForm.reset();
                this.previewStatus = "Preview cancelled.";
            } catch (error) {
                this.$refs.restoreForm.reset();
                this.showFailure(error);
            } finally {
                this.busy = null;
            }
        },
    };
}

window.noteBackupWorkflow = noteBackupWorkflow;
