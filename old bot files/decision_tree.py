import pandas as pd
import numpy as np
import matplotlib.pyplot as plt
import joblib

from sklearn.model_selection import train_test_split
from sklearn.metrics import accuracy_score, roc_auc_score
from sklearn.tree import DecisionTreeClassifier, plot_tree, _tree

# ==========================================
# 1. Load and Preprocess Data
# ==========================================
file_path = "Data 2015-2025_new.xlsx"

df_pos = pd.read_excel(file_path, sheet_name="חיוביים")
df_neg = pd.read_excel(file_path, sheet_name="שליליים")

df_pos["Label"] = 1
df_neg["Label"] = 0

data = pd.concat([df_pos, df_neg], ignore_index=True)
data = data.rename(columns={
    "גיל": "Age", "מגדר": "Gender", "קבוצת סיכון ראשית": "RiskGroup",
    "מעמד אזרחי": "CivilStatus", "דת": "Religion", 
    "ארץ מוצא": "CountryOfOrigin", "יבשת": "Continent"
})

# Feature Engineering
data["Risk_Behavioral"] = data["RiskGroup"].isin(["MSM", "ריבוי פרטנרים", "זנות פעילה"]).astype(int)
data["Risk_Substance"] = data["RiskGroup"].isin(["סמים בהזרקה"]).astype(int)
data["Risk_MigrationRelated"] = data["RiskGroup"].isin(["אפריקה מדרום לסהרה", "קרן אפריקה", "ברית המועצות"]).astype(int)
data["Risk_Unknown"] = data["RiskGroup"].isin(["ללא קבוצת סכון ידועה"]).astype(int)

numeric_features = ["Age"]
base_categorical_features = ["Gender", "CivilStatus", "Religion", "CountryOfOrigin", "Continent"]
engineered_binary_features = ["Risk_Behavioral", "Risk_Substance", "Risk_MigrationRelated", "Risk_Unknown"]

cols_needed = numeric_features + base_categorical_features + engineered_binary_features + ["Label"]
data = data[cols_needed].dropna(subset=["Label", "Age"])
data["Age"] = pd.to_numeric(data["Age"], errors="coerce")

data_encoded = pd.get_dummies(
    data[numeric_features + base_categorical_features + engineered_binary_features], 
    columns=base_categorical_features
)

X = data_encoded
y = data["Label"].astype(int)

# Shuffle & Split
X_train, X_test, y_train, y_test = train_test_split(X, y, test_size=0.2, random_state=42, stratify=y)
features = list(X.columns)

# ==========================================
# 2. Model Training
# ==========================================
tree = DecisionTreeClassifier(
    max_depth=3, min_samples_leaf=20, criterion="entropy",
    class_weight={0: 1.0, 1: 3.0}, random_state=42
)
tree.fit(X_train, y_train)

# ==========================================
# 3. Rule Extraction (Requested Columns Only)
# ==========================================
def _get_leaf_paths(decision_tree, feature_names):
    tree_ = decision_tree.tree_
    feat_name = [feature_names[i] if i != _tree.TREE_UNDEFINED else None for i in tree_.feature]
    paths = []
    def recurse(node, path):
        if tree_.feature[node] != _tree.TREE_UNDEFINED:
            name = feat_name[node]
            thr = tree_.threshold[node]
            recurse(tree_.children_left[node], path + [(name, "<=", thr)])
            recurse(tree_.children_right[node], path + [(name, ">", thr)])
        else:
            paths.append((node, path))
    recurse(0, [])
    return paths

def _path_to_rule(path):
    if not path: return "(Total Sample)"
    rules = []
    for (f, op, t) in path:
        condition = f"{f} = 0" if (abs(t - 0.5) < 1e-9 and op == "<=") else f"{f} = 1" if (abs(t - 0.5) < 1e-9) else f"{f} {op} {t:.3f}"
        rules.append(condition)
    return " AND ".join(rules)

def leaf_rule_table(tree, feature_names, X_data, y_data):
    X_df = X_data if isinstance(X_data, pd.DataFrame) else pd.DataFrame(X_data, columns=feature_names)
    y_arr = np.asarray(y_data)
    leaf_ids = tree.apply(X_df)
    paths = _get_leaf_paths(tree, feature_names)
    n_total = len(y_arr)
    rows = []

    for leaf_node, path in paths:
        mask = (leaf_ids == leaf_node)
        n = int(mask.sum())
        if n == 0: continue
        pred = int(tree.classes_[np.argmax(tree.tree_.value[leaf_node][0])])
        acc = float((y_arr[mask] == pred).mean())
        
        # EXACT COLUMNS REQUESTED
        rows.append({
            "rule": _path_to_rule(path),
            "predicted_class": "Positive" if pred == 1 else "Negative",
            "samples": n,
            "coverage_pct": 100.0 * n / n_total,
            "rule_accuracy_pct": 100.0 * acc
        })
    return pd.DataFrame(rows).sort_values(["coverage_pct"], ascending=False).reset_index(drop=True)

# Output Results
df_test_rules = leaf_rule_table(tree, features, X_test, y_test)
pd.set_option('display.max_colwidth', None)
print("\n=== Rules on TEST ===")
print(df_test_rules[["rule", "predicted_class", "samples", "coverage_pct", "rule_accuracy_pct"]])

# Save results
df_test_rules.to_csv("tree_rules_simple.csv", index=False, encoding="utf-8-sig")
joblib.dump(tree, "clinic_decision_tree.pkl")
