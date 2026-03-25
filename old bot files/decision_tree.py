import pandas as pd
import numpy as np
from sklearn.model_selection import train_test_split, cross_validate
from sklearn.metrics import (
    accuracy_score,
    roc_auc_score,
    roc_curve,
    precision_score,
    recall_score,
    precision_recall_curve
)
from sklearn.tree import DecisionTreeClassifier, plot_tree, export_text
import matplotlib.pyplot as plt
import joblib

# ===========================
# Load and preprocess the data
# ===========================

file_path = "Data 2015-2025_new.xlsx"

df_pos = pd.read_excel(file_path, sheet_name="חיוביים")  # positive cases
df_neg = pd.read_excel(file_path, sheet_name="שליליים")  # negative cases

df_pos["Label"] = 1
df_neg["Label"] = 0

data = pd.concat([df_pos, df_neg], ignore_index=True)

data = data.rename(columns={
    "גיל": "Age",
    "מגדר": "Gender",
    "קבוצת סיכון ראשית": "RiskGroup",
    "מעמד אזרחי": "CivilStatus",
    "דת": "Religion",
    "ארץ מוצא": "CountryOfOrigin",
    "יבשת": "Continent"
})

# Normalize string columns (helps avoid hidden whitespace differences)
for col in ["Gender", "RiskGroup", "CivilStatus", "Religion", "CountryOfOrigin", "Continent"]:
    if col in data.columns:
        data[col] = data[col].astype(str).str.strip()

# ===========================
# Feature Engineering / Risk Group Breakdown
# ===========================

# Your original risk-group values:
# MSM
# אפריקה מדרום לסהרה
# ברית המועצות
# זנות פעילה
# ללא קבוצת סכון ידועה
# סמים בהזרקה
# קרן אפריקה
# ריבוי פרטנרים

# Build grouped risk-factor features (binary flags)
data["Risk_Behavioral"] = data["RiskGroup"].isin([
    "MSM",
    "ריבוי פרטנרים",
    "זנות פעילה"
]).astype(int)

data["Risk_Substance"] = data["RiskGroup"].isin([
    "סמים בהזרקה"
]).astype(int)

data["Risk_MigrationRelated"] = data["RiskGroup"].isin([
    "אפריקה מדרום לסהרה",
    "קרן אפריקה",
    "ברית המועצות"
]).astype(int)

data["Risk_Unknown"] = data["RiskGroup"].isin([
    "ללא קבוצת סכון ידועה"
]).astype(int)

# Optional: keep the original RiskGroup as well (comment out if you want ONLY grouped vars)
# Keeping it can sometimes help, but it may also reduce interpretability.
KEEP_ORIGINAL_RISKGROUP = False

# ===========================
# Choose features
# ===========================

numeric_features = ["Age"]

# We exclude RiskGroup from one-hot encoding if we are using grouped variables only
base_categorical_features = ["Gender", "CivilStatus", "Religion", "CountryOfOrigin", "Continent"]

if KEEP_ORIGINAL_RISKGROUP:
    base_categorical_features.append("RiskGroup")

engineered_binary_features = [
    "Risk_Behavioral",
    "Risk_Substance",
    "Risk_MigrationRelated",
    "Risk_Unknown"
]

cols_needed = numeric_features + base_categorical_features + engineered_binary_features + ["Label"]
data = data[cols_needed]

data["Age"] = pd.to_numeric(data["Age"], errors="coerce")
data = data.dropna(subset=["Label", "Age"])

# One-hot encode categorical variables (no artificial ordering)
data_encoded = pd.get_dummies(
    data[numeric_features + base_categorical_features + engineered_binary_features],
    columns=base_categorical_features,
    drop_first=False
)

X = data_encoded
y = data["Label"].astype(int)

# Shuffle (recommended)
data_shuffled = pd.concat([X, y], axis=1).sample(frac=1, random_state=42).reset_index(drop=True)
X = data_shuffled.drop(columns=["Label"])
y = data_shuffled["Label"]

features = list(X.columns)

# ===========================
# Train-Test Split (80/20)
# ===========================

X_train, X_test, y_train, y_test = train_test_split(
    X, y,
    test_size=0.2,
    random_state=42,
    stratify=y
)

# ===========================
# Train Decision Tree
# ===========================

tree = DecisionTreeClassifier(
    max_depth=3,
    min_samples_leaf=20,
    criterion="entropy",
    class_weight={0: 1.0, 1: 3.0},
    random_state=42
)

tree.fit(X_train, y_train)
y_pred = tree.predict(X_test)

# ===========================
# Model Evaluation
# ===========================

print("\nTest Accuracy:", accuracy_score(y_test, y_pred))

y_prob = tree.predict_proba(X_test)[:, 1]
print("ROC-AUC:", roc_auc_score(y_test, y_prob))

# ===========================
# Threshold evaluation
# ===========================

threshold = 0.30
y_pred_thresh = (y_prob >= threshold).astype(int)

print(f"\nThreshold-based Evaluation (threshold = {threshold})")
print("Precision:", precision_score(y_test, y_pred_thresh, zero_division=0))
print("Recall:", recall_score(y_test, y_pred_thresh, zero_division=0))

# ===========================
# Cross-Validation
# ===========================

print("\nRunning Multi-Metric Cross-Validation...")
cv_results = cross_validate(
    tree, X, y, cv=5,
    scoring=["accuracy", "precision", "recall", "roc_auc"]
)

print("Mean CV Accuracy:", cv_results["test_accuracy"].mean())
print("Mean CV AUC:", cv_results["test_roc_auc"].mean())

# ===========================
# ROC Curve
# ===========================

fpr, tpr, _ = roc_curve(y_test, y_prob)
plt.plot(fpr, tpr)
plt.plot([0, 1], [0, 1], "--")
plt.title("ROC Curve")
plt.xlabel("False Positive Rate")
plt.ylabel("True Positive Rate")
plt.show()

# ===========================
# Precision-Recall sample points
# ===========================

prec, rec, thr = precision_recall_curve(y_test, y_prob)
for p, r, t in zip(prec[::50], rec[::50], thr[::50]):
    print(f"Thresh={t:.2f}, Precision={p:.2f}, Recall={r:.2f}")

# ===========================
# Tree rules + visualization
# ===========================

print("\nDecision Rules:\n")
print(export_text(tree, feature_names=features))

plt.figure(figsize=(12, 8))
plot_tree(tree, feature_names=features, class_names=["Negative", "Positive"], filled=True)
plt.title("Simplified Decision Tree (STD Clinic Recommendation)")
plt.savefig("decision_tree.png", dpi=300, bbox_inches="tight")
plt.show()

# ===========================
# Save model
# ===========================

joblib.dump(tree, "clinic_decision_tree.pkl")
